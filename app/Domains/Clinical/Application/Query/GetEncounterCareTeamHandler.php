<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\CareTeamMembershipSummary;
use App\Domains\Clinical\Domain\CareTeamMembershipReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetEncounterCareTeam` (IC §6.1 / §6.3; ADR-142 D-P). No
 * transaction — the QueryBus runs inside the request tenant scope (IC §6.1). No
 * audit, no event, no state mutation.
 *
 * Flow (the `GetPatientProblemsHandler` originating-facility-refinement shape):
 *   1. resolve the authenticated actor (from `Auth`, never a query field).
 *   2. compute the caller's ORIGINATING-facility whitelist — every facility the
 *      actor may select where they effectively hold `clinical.care_team.read`
 *      (`ScopeChecker::selectableFacilities` + `effectivePermissions`).
 *   3. `CareTeamMembershipReader::forEncounter()` returns only that encounter's
 *      memberships whose originating `facility_id` is in the whitelist,
 *      deterministically ordered (`is_provider_of_record` DESC, `team_role`,
 *      `active_from`, `id`), NO pagination. `$includeEnded` controls whether
 *      ENDED rows are included. An empty whitelist yields an empty list — never
 *      an authorization disclosure (ADR-142 D-M — originating-facility-only).
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

        $inScopeFacilityIds = array_values(array_filter(
            $this->scope->selectableFacilities($user),
            fn (string $facilityId): bool => in_array(
                'clinical.care_team.read',
                $this->scope->effectivePermissions($user, $facilityId),
                true,
            ),
        ));

        return $this->reader->forEncounter($query->encounterId, $inScopeFacilityIds, $query->includeEnded);
    }
}
