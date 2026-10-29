<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\CareTeamMembership;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one care-team membership by id (12-clinical-domain.md §4.1; ADR-142
 * D-P). Protected (IC §6.2): care-team membership is originating-facility-scoped
 * (ADR-142 D-M). Needed so a downstream consumer (e.g. Care Coordination's
 * `coordinating_provider_ref`, PHASE 7) can resolve one specific membership.
 *
 * The `#[ProtectedQuery]` gate is the COARSE tenant + `clinical.care_team.read`
 * check (`CareTeamMembershipPolicy::view`, class-level). `GetCareTeamMembershipHandler`
 * applies the per-membership ORIGINATING-facility refinement (ADR-142 D-N): a
 * membership whose originating facility is outside the caller's scope resolves
 * as `NotFound`, never an authorization disclosure. NO side effects.
 */
#[ProtectedQuery(ability: 'view', subjectType: CareTeamMembership::class)]
final readonly class GetCareTeamMembership
{
    public function __construct(public string $careTeamMembershipId)
    {
        if (! Str::isUuid($careTeamMembershipId)) {
            throw new InvalidArgumentException('GetCareTeamMembership::$careTeamMembershipId must be a UUID (IC §3).');
        }
    }
}
