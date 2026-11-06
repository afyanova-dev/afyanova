<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\CareTeamMembershipSummary;
use App\Domains\Clinical\Domain\CareTeamMembershipReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetEncounterCareTeam` (IC §6.1 / §6.3; ADR-142 D-P). No
 * transaction — the QueryBus runs inside the request tenant scope (IC §6.1). No
 * audit, no event, no state mutation.
 *
 * Flow:
 *   1. resolve the authenticated actor (from `Auth`, never a query field).
 *   2. compute facilities authorized in the current context: the active
 *      facility, or explicitly authorized targets in General.
 *   3. `CareTeamMembershipReader::forEncounter()` returns only that encounter's
 *      memberships whose originating `facility_id` is in that context scope,
 *      deterministically ordered (`is_provider_of_record` DESC, `team_role`,
 *      `active_from`, `id`), NO pagination. `$includeEnded` controls whether
 *      ENDED rows are included. An empty context scope yields an empty list —
 *      never an authorization disclosure (ADR-142 D-M — originating-facility-only).
 *
 * NO cross-domain call.
 *
 * @return list<CareTeamMembershipSummary>
 */
final class GetEncounterCareTeamHandler
{
    public function __construct(
        private readonly CareTeamMembershipReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<CareTeamMembershipSummary>
     */
    public function __invoke(GetEncounterCareTeam $query): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('GetEncounterCareTeam dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        $inScopeFacilityIds = $this->scope->facilityIdsWithPermissionInContext(
            $user,
            $this->context,
            'clinical.care_team.read',
        );

        return $this->reader->forEncounter($query->encounterId, $inScopeFacilityIds, $query->includeEnded);
    }
}
