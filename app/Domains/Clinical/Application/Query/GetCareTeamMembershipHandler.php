<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\CareTeamMembershipSummary;
use App\Domains\Clinical\Domain\CareTeamMembershipReader;
use App\Domains\Clinical\Domain\Model\CareTeamMembership;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetCareTeamMembership` (IC §6.1 / §6.3; ADR-142 D-P). No
 * transaction — the QueryBus runs inside the request tenant scope (IC §6.1). No
 * audit, no event, no state mutation.
 *
 * Flow (the `GetProblemHandler` shape):
 *   1. `CareTeamMembershipReader::summary()` — RLS-scoped; `NotFound` (404)
 *      when the membership is missing or in another tenant.
 *   2. ADR-142 D-N originating-facility refinement — the authenticated actor
 *      (from `Auth`, never a query field) must hold `clinical.care_team.read`
 *      at THIS membership's ORIGINATING facility
 *      (`ScopeChecker::effectivePermissions`). Otherwise `NotFound` — an
 *      out-of-scope membership is indistinguishable from an absent one
 *      (ADR-142 D-M — originating-facility-only visibility).
 */
final class GetCareTeamMembershipHandler
{
    public function __construct(
        private readonly CareTeamMembershipReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetCareTeamMembership $query): CareTeamMembershipSummary
    {
        $summary = $this->reader->summary($query->careTeamMembershipId);

        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('GetCareTeamMembership dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        if (! in_array(
            'clinical.care_team.read',
            $this->scope->effectivePermissions($user, $summary->facilityId),
            true,
        )) {
            throw NotFound::aggregate(CareTeamMembership::class, $query->careTeamMembershipId);
        }

        return $summary;
    }
}
