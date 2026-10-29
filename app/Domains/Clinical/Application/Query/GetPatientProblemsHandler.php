<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\ProblemSummary;
use App\Domains\Clinical\Domain\ProblemReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
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
 *   2. compute the caller's ORIGINATING-facility whitelist — every facility the
 *      actor may select where they effectively hold `clinical.problems.read`
 *      (`ScopeChecker::selectableFacilities` + `effectivePermissions`).
 *   3. `ProblemReader::forPatient()` returns only that patient's problems whose
 *      originating `facility_id` is in the whitelist, optional status filter,
 *      deterministically ordered, NO pagination (ADR-140 D-L / clarification
 *      lock Decision 3). An empty whitelist yields an empty list — never an
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

        $inScopeFacilityIds = array_values(array_filter(
            $this->scope->selectableFacilities($user),
            fn (string $facilityId): bool => in_array(
                'clinical.problems.read',
                $this->scope->effectivePermissions($user, $facilityId),
                true,
            ),
        ));

        return $this->reader->forPatient($query->patientId, $query->status, $inScopeFacilityIds);
    }
}
