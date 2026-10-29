<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Authorization;

use App\Domains\Clinical\Application\Command\AddCareTeamMember;
use App\Domains\Clinical\Application\Command\ChangeProviderOfRecord;
use App\Domains\Clinical\Application\Command\EndCareTeamMembership;
use App\Domains\Clinical\Domain\Model\CareTeamMembership;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * CareTeamMembership aggregate policy (IC §12; 12-clinical-domain.md §87–§90,
 * §159; ADR-133, ADR-142 D-N). The exact `ProblemPolicy` shape:
 *
 *   - class-level checks (`record`, `changeProviderOfRecord`, `view`) use
 *     `permits()` — the key in the actor's CURRENT facility context;
 *   - the per-subject check (`end`) evaluates the key against the MEMBERSHIP's
 *     ORIGINATING facility via `ScopeChecker::effectivePermissions`, because
 *     `clin_care_team_memberships` RLS is tenant-scoped (§88, Invariant 24/25;
 *     ADR-142 D-M — originating-facility-only visibility, reads AND writes).
 *
 * The handlers add a further ADR-142 D-N refinement where the class-level check
 * cannot reach the target facility (`AddCareTeamMember` against the encounter's
 * facility; `ChangeProviderOfRecord` against the encounter's memberships'
 * shared facility; `GetEncounterCareTeam` / `GetCareTeamMembership` against the
 * membership's facility).
 *
 * Exactly two keys (ADR-142 D-N): `clinical.care_team.read` and
 * `clinical.care_team.record`. **`record` authorizes Add, End AND
 * ChangeProviderOfRecord** — there is no `clinical.care_team.manage` /
 * `.assign` / `.remove` / `.change_provider` key. Care-team membership is NOT
 * itself an authorization input for any C.6 command or any other Clinical
 * command (ADR-133 / `12` §159). Break-glass / sensitivity remain DEFERRED
 * (ADR-133).
 */
final class CareTeamMembershipPolicy extends BasePolicy
{
    /**
     * AddCareTeamMember — class-level (create) check; there is no subject yet
     * (IC §5.1). `permits()` resolves `clinical.care_team.record` in the actor's
     * current facility context; `AddCareTeamMemberHandler` adds the
     * per-encounter-facility refinement (ADR-142 D-N).
     */
    public function record(User $user, ?AddCareTeamMember $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.care_team.record');
    }

    /**
     * ChangeProviderOfRecord — class-level check; the command is keyed by
     * encounter, not a single membership id (IC §5.1). Gated by the SAME
     * `clinical.care_team.record` key (ADR-142 D-N — a provider-of-record
     * hand-off is still `record`). `ChangeProviderOfRecordHandler` adds the
     * refinement against the encounter's memberships' shared originating
     * facility.
     */
    public function changeProviderOfRecord(User $user, ?ChangeProviderOfRecord $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.care_team.record');
    }

    /**
     * GetEncounterCareTeam / GetCareTeamMembership (`#[ProtectedQuery]`, IC
     * §6.2) — class-level check; the QueryBus never loads a subject. Coarse
     * tenant + `clinical.care_team.read`. The per-membership originating-facility
     * refinement is the handler's (ADR-142 D-N).
     */
    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.care_team.read');
    }

    /**
     * EndCareTeamMembership — per-subject check; the bus loaded the membership
     * being ended from its (RLS-scoped) repository. Gated by the
     * **`clinical.care_team.record`** key (ADR-142 D-N — the same key as adding),
     * evaluated against the MEMBERSHIP's ORIGINATING facility (§88, Invariant
     * 24/25; ADR-142 D-M).
     */
    public function end(User $user, CareTeamMembership $membership, ?EndCareTeamMembership $command = null): bool
    {
        return $this->recordAtOriginatingFacility($user, $membership);
    }

    private function recordAtOriginatingFacility(User $user, CareTeamMembership $membership): bool
    {
        return $this->sameTenant($user, (string) $membership->tenant_id)
            && in_array(
                'clinical.care_team.record',
                $this->scope->effectivePermissions($user, (string) $membership->facility_id),
                true,
            );
    }
}
