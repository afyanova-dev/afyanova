<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\CareTeamMembership;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one encounter's clinical care team (12-clinical-domain.md §4.1, §162;
 * ADR-142 D-P). Protected (IC §6.2): care-team membership is
 * originating-facility-scoped (ADR-142 D-M — NOT the ADR-141 D-M tenant-wide
 * exception).
 *
 * The `#[ProtectedQuery]` gate is the COARSE tenant + `clinical.care_team.read`
 * check (`CareTeamMembershipPolicy::view`, class-level — the QueryBus never
 * loads a subject). `GetEncounterCareTeamHandler` resolves the caller's
 * in-scope facilities and returns only memberships whose ORIGINATING facility
 * is among them.
 *
 * `$includeEnded` (default false) returns only ACTIVE memberships; true also
 * returns ENDED memberships (the "everyone who was ever on this team" read —
 * there is NO separate history query, ADR-142 D-P). The result is a bounded,
 * deterministically-ordered `list<CareTeamMembershipSummary>` — NO pagination.
 * The single active provider-of-record (if any) is identified within the result
 * by `isProviderOfRecord` — there is NO dedicated `GetProviderOfRecord` query
 * (ADR-142 D-P). NO side effects: no event, no audit, no state mutation.
 */
#[ProtectedQuery(ability: 'view', subjectType: CareTeamMembership::class)]
final readonly class GetEncounterCareTeam
{
    public function __construct(
        public string $encounterId,
        public bool $includeEnded = false,
    ) {
        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('GetEncounterCareTeam::$encounterId must be a UUID (IC §3).');
        }
    }
}
