<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\ProblemSummary;
use App\Domains\Clinical\Domain\ProblemReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetPatientProblems` (IC §6.1 / §6.3; ADR-140 D-L). No
 * transaction — the QueryBus runs inside the request tenant scope (IC §6.1).
 * No audit, no event, no state mutation.
 *
 * Flow:
 *   1. resolve the authenticated actor (from `Auth`, never a query field — the
 *      `SearchPatients` / `GetDiagnosis` precedent).
 *   2. compute facility IDs authorized in the current context — the active
 *      facility, or explicitly authorized facilities in General.
 *   3. `ProblemReader::forPatient()` returns only that patient's problems whose
 *      originating `facility_id` is in that context scope, optional status filter,
 *      deterministically ordered, NO pagination (ADR-140 D-L / clarification
 *      lock Decision 3). An empty facility scope yields an empty list — never an
 *      authorization disclosure (ADR-140 D-B — originating-facility-only).
 *
 * NO cross-domain call. `code` / `description` ARE returned to an authorized
 * caller (the `#[ProtectedQuery]` gate + this refinement IS the authorization —
 * the `DiagnosisSummary` precedent).
 *
 * @return list<ProblemSummary>
 */
final class GetPatientProblemsHandler
{
    public function __construct(
        private readonly ProblemReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<ProblemSummary>
     */
    public function __invoke(GetPatientProblems $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPatientProblems dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        $inScopeFacilityIds = $this->scope->facilityIdsWithPermissionInContext(
            $user,
            $this->context,
            'clinical.problems.read',
        );

        return $this->reader->forPatient($query->patientId, $query->status, $inScopeFacilityIds);
    }
}
