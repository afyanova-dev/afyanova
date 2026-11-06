<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\ProcedureSummary;
use App\Domains\Clinical\Domain\ClinicalProcedureReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetEncounterProcedures` (IC §6.1 / §6.3; ADR-146 D-Q). No
 * transaction, no audit, no event, no state mutation.
 *
 * Flow:
 *   1. resolve the authenticated actor (from `Auth`, never a query field).
 *   2. compute facilities authorized in the current context: the active
 *      facility, or explicitly authorized targets in General.
 *   3. `ClinicalProcedureReader::forEncounter()` returns only that encounter's
 *      procedures whose originating `facility_id` is in that context scope,
 *      deterministically ordered, NO pagination. An empty context scope yields an
 *      empty list — never an authorization disclosure.
 *
 * @return list<ProcedureSummary>
 */
final class GetEncounterProceduresHandler
{
    public function __construct(
        private readonly ClinicalProcedureReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
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

        $inScopeFacilityIds = $this->scope->facilityIdsWithPermissionInContext(
            $user,
            $this->context,
            'clinical.procedures.read',
        );

        return $this->reader->forEncounter($query->encounterId, $inScopeFacilityIds);
    }
}
