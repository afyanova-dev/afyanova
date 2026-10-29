<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\ProcedureSummary;
use App\Domains\Clinical\Domain\ClinicalProcedureReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetEncounterProcedures` (IC §6.1 / §6.3; ADR-146 D-Q). No
 * transaction, no audit, no event, no state mutation.
 *
 * Flow (the `GetEncounterTriageAssessments` originating-facility-refinement
 * shape):
 *   1. resolve the authenticated actor (from `Auth`, never a query field).
 *   2. compute the caller's ORIGINATING-facility whitelist — every facility the
 *      actor may select where they effectively hold `clinical.procedures.read`
 *      (`ScopeChecker::selectableFacilities` + `effectivePermissions`).
 *   3. `ClinicalProcedureReader::forEncounter()` returns only that encounter's
 *      procedures whose originating `facility_id` is in the whitelist,
 *      deterministically ordered, NO pagination. An empty whitelist yields an
 *      empty list — never an authorization disclosure.
 *
 * @return list<ProcedureSummary>
 */
final class GetEncounterProceduresHandler
{
    public function __construct(
        private readonly ClinicalProcedureReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<ProcedureSummary>
     */
    public function __invoke(GetEncounterProcedures $query): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('GetEncounterProcedures dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        $inScopeFacilityIds = array_values(array_filter(
            $this->scope->selectableFacilities($user),
            fn (string $facilityId): bool => in_array(
                'clinical.procedures.read',
                $this->scope->effectivePermissions($user, $facilityId),
                true,
            ),
        ));

        return $this->reader->forEncounter($query->encounterId, $inScopeFacilityIds);
    }
}
