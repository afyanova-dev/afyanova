<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\EndCareTeamMembership;
use App\Domains\Clinical\Application\Query\GetEncounterCareTeam;
use App\Domains\Clinical\Contracts\Dto\CareTeamMembershipSummary;
use App\Domains\Clinical\Domain\ValueObject\TeamRole;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.6) gate — GetEncounterCareTeam through the real QueryBus /
 | #[ProtectedQuery] pipeline (IC §6; ADR-142 D-P). Read-only: no event, no
 | audit, no state change. ORIGINATING-facility-scoped (ADR-142 D-M — NOT the
 | ADR-141 D-M tenant-wide exception). Active by default; includeEnded exposes
 | history; deterministic order; no pagination.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/care_team.php';

/**
 * @return list<CareTeamMembershipSummary>
 */
function dispatchGetEncounterCareTeam(string $tenantId, ?string $facilityId, string $encounterId, bool $includeEnded = false): array
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(QueryBus::class)->dispatch(new GetEncounterCareTeam($encounterId, $includeEnded)));
}

function endMembershipViaBus(string $tenantId, ?string $facilityId, string $membershipId, string $actorId): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
        new EndCareTeamMembership($membershipId, $actorId)
    ));
}

test('returns the active care team by default; the provider-of-record is first; ended members are excluded', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $por = makeStaffUser((string) $tenant->id);
    $nurse = makeStaffUser((string) $tenant->id);
    $goner = makeStaffUser((string) $tenant->id);

    clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $nurse, (string) $user->id, ['teamRole' => TeamRole::NURSE]);
    clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $por, (string) $user->id, ['teamRole' => TeamRole::ATTENDING, 'isProviderOfRecord' => true]);
    $gonerMembership = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $goner, (string) $user->id, ['teamRole' => TeamRole::CONSULTANT]);
    endMembershipViaBus((string) $tenant->id, (string) $facility->id, $gonerMembership, (string) $user->id);

    $team = dispatchGetEncounterCareTeam((string) $tenant->id, (string) $facility->id, $encounterId);

    expect($team)->toHaveCount(2)
        ->and($team[0])->toBeInstanceOf(CareTeamMembershipSummary::class)
        ->and($team[0]->isProviderOfRecord)->toBeTrue()          // deterministic order: PoR first
        ->and($team[0]->staffUserId)->toBe($por)
        ->and($team[1]->isProviderOfRecord)->toBeFalse()
        ->and($team[1]->staffUserId)->toBe($nurse)
        ->and($team[0]->activeTo)->toBeNull()
        ->and($team[0]->endReason)->toBeNull();
});

test('includeEnded = true also returns ENDED memberships (the history read — no separate query)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $m = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id);
    endMembershipViaBus((string) $tenant->id, (string) $facility->id, $m, (string) $user->id);

    expect(dispatchGetEncounterCareTeam((string) $tenant->id, (string) $facility->id, $encounterId))->toHaveCount(0)
        ->and(dispatchGetEncounterCareTeam((string) $tenant->id, (string) $facility->id, $encounterId, true))->toHaveCount(1);

    $ended = dispatchGetEncounterCareTeam((string) $tenant->id, (string) $facility->id, $encounterId, true)[0];
    expect($ended->activeTo)->not->toBeNull()
        ->and($ended->removedByUserId)->toBe((string) $user->id);
});

test('Q12 originating-facility scoping: a reader at facility A CANNOT see the care team of an encounter at facility B', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facilityA->id, (string) $user->id);
    clinicalAddCareTeamMember((string) $tenant->id, (string) $facilityA->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id);

    // move the encounter + membership to facility B (outside the caller's scope)
    $facilityB = (string) Str::uuid7();
    Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('encounter_id', $encounterId)->update(['facility_id' => $facilityB]));

    // GetProblem-style: an out-of-scope encounter yields an EMPTY team, never a disclosure
    expect(dispatchGetEncounterCareTeam((string) $tenant->id, (string) $facilityA->id, $encounterId))->toBe([]);
});

test('a caller without clinical.care_team.read is denied (AuthorizationException)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture(['clinical.care_team.record']);
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => dispatchGetEncounterCareTeam((string) $tenant->id, (string) $facility->id, $encounterId))
        ->toThrow(AuthorizationException::class);
});

test('the read writes no audit row and no outbox event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id);

    $auditBefore = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->count());
    $outboxBefore = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->count());

    dispatchGetEncounterCareTeam((string) $tenant->id, (string) $facility->id, $encounterId);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->count()))->toBe($auditBefore)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->count()))->toBe($outboxBefore);
});

test('a cross-tenant encounter yields an empty team (RLS confines the read)', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = careTeamFixture();
    [, $encounterA] = clinicalPatientWithEncounter((string) $tenantA->id, (string) $facilityA->id, (string) $userA->id);
    clinicalAddCareTeamMember((string) $tenantA->id, (string) $facilityA->id, $encounterA, makeStaffUser((string) $tenantA->id), (string) $userA->id);

    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = careTeamFixture();
    test()->actingAs($userB);

    expect(dispatchGetEncounterCareTeam((string) $tenantB->id, (string) $facilityB->id, $encounterA))->toBe([]);
});

test('GetEncounterCareTeam rejects a malformed id at construction (IC §3)', function () {
    expect(fn () => new GetEncounterCareTeam('not-a-uuid'))->toThrow(InvalidArgumentException::class);
});
