<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\TriageAssessmentSummary;
use App\Domains\Clinical\Domain\TriageAssessmentReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetEncounterTriageAssessments` (IC §6.1 / §6.3; ADR-145 D-H).
 * No transaction — the QueryBus runs inside the request tenant scope (IC §6.1).
 * No audit, no event, no state mutation.
 *
 * Flow:
 *   1. resolve the authenticated actor (from `Auth`, never a query field).
 *   2. compute facilities authorized in the current context: the active
 *      facility, or explicitly authorized targets in General.
 *   3. `TriageAssessmentReader::forEncounter()` returns only that encounter's
 *      assessments whose originating `facility_id` is in that context scope,
 *      deterministically ordered (`occurred_at`, then `id`), NO pagination.
 *      `$currentOnly` controls whether superseded assessments are included. An
 *      empty context scope yields an empty list — never an authorization disclosure
 *      (ADR-145 D-P).
 *
 * NO cross-domain call. The `chiefComplaint` / `acuity` / `consciousness` ARE
 * returned to an authorized caller (the `#[ProtectedQuery]` gate + this
 * refinement IS the authorization — the `GetPatientProblems` precedent).
 *
 * @return list<TriageAssessmentSummary>
 */
final class GetEncounterTriageAssessmentsHandler
{
    public function __construct(
        private readonly TriageAssessmentReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<TriageAssessmentSummary>
     */
    public function __invoke(GetEncounterTriageAssessments $query): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('GetEncounterTriageAssessments dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        $inScopeFacilityIds = $this->scope->facilityIdsWithPermissionInContext(
            $user,
            $this->context,
            'clinical.triage.read',
        );

        return $this->reader->forEncounter($query->encounterId, $inScopeFacilityIds, $query->currentOnly);
    }
}
