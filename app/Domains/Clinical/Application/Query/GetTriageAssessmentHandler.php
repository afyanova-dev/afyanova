<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\TriageAssessmentSummary;
use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Domains\Clinical\Domain\TriageAssessmentReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetTriageAssessment` (IC §6.1 / §6.3; ADR-145 D-H). No
 * transaction — the QueryBus runs inside the request tenant scope (IC §6.1). No
 * audit, no event, no state mutation.
 *
 * Flow:
 *   1. `TriageAssessmentReader::summary()` — RLS-scoped; `NotFound` (404) when
 *      the assessment is missing or in another tenant.
 *   2. ADR-145 facility refinement — the authenticated actor (from `Auth`,
 *      never a query field) must hold `clinical.triage.read` at THIS
 *      assessment's facility (`ScopeChecker::effectivePermissions`). Otherwise
 *      `NotFound` — §88 / Invariant 24/25: an out-of-scope assessment is
 *      indistinguishable from an absent one.
 *
 * The `chiefComplaint` / `acuity` / `consciousness` ARE returned to an
 * authorized caller — the `#[ProtectedQuery]` gate plus this refinement IS the
 * authorization (the `DiagnosisSummary` precedent). NO cross-domain call.
 */
final class GetTriageAssessmentHandler
{
    public function __construct(
        private readonly TriageAssessmentReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetTriageAssessment $query): TriageAssessmentSummary
    {
        $summary = $this->reader->summary($query->triageAssessmentId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetTriageAssessment dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        if (! in_array(
            'clinical.triage.read',
            $this->scope->effectivePermissions($user, $summary->facilityId),
            true,
        )) {
            throw NotFound::aggregate(TriageAssessment::class, $query->triageAssessmentId);
        }

        return $summary;
    }
}
