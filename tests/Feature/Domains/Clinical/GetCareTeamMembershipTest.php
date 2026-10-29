<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetCareTeamMembership;
use App\Domains\Clinical\Contracts\Dto\CareTeamMembershipSummary;
use App\Domains\Clinical\Domain\ValueObject\TeamRole;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.6) gate — GetCareTeamMembership (by id) through the real QueryBus
 | / #[ProtectedQuery] pipeline (IC §6; ADR-142 D-P). Coarse policy +
 | handler-side ORIGINATING-facility refinement -> NotFound (the GetProblem
 | precedent). Read-only.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/care_team.php';

function dispatchGetCareTeamMembership(string $tenantId, ?string $facilityId, string $membershipId): CareTeamMembershipSummary
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(QueryBus::class)->dispatch(new GetCareTeamMembership($membershipId)));
}

test('returns the full CareTeamMembershipSummary for an authorized reader', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $staffUserId = makeStaffUser((string) $tenant->id);
    $membershipId = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffUserId, (string) $user->id, [
        'teamRole' => TeamRole::RESIDENT,
        'isProviderOfRecord' => true,
    ]);

    $s = dispatchGetCareTeamMembership((string) $tenant->id, (string) $facility->id, $membershipId);

    expect($s)->toBeInstanceOf(CareTeamMembershipSummary::class)
        ->and($s->membershipId)->toBe($membershipId)
        ->and($s->encounterId)->toBe($encounterId)
        ->and($s->patientId)->toBe($patientId)
        ->and($s->facilityId)->toBe((string) $facility->id)
        ->and($s->staffUserId)->toBe($staffUserId)
        ->and($s->teamRole)->toBe('RESIDENT')
        ->and($s->isProviderOfRecord)->toBeTrue()
        ->and($s->activeFrom)->toBeString()
        ->and($s->activeTo)->toBeNull()
        ->and($s->addedByUserId)->toBe((string) $user->id)
        ->and($s->removedByUserId)->toBeNull()
        ->and($s->endReason)->toBeNull()
        ->and($s->addedAt)->toBeString()
        ->and($s->version)->toBe(0);

    // ADR-142 D-H / reconciliation R-1 — no provider-of-record rationale field
    expect((array) $s)->not->toHaveKey('providerOfRecordReason');
});

test('a missing / cross-tenant membership -> NotFound', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = careTeamFixture();
    [, $encounterA] = clinicalPatientWithEncounter((string) $tenantA->id, (string) $facilityA->id, (string) $userA->id);
    $membershipId = clinicalAddCareTeamMember((string) $tenantA->id, (string) $facilityA->id, $encounterA, makeStaffUser((string) $tenantA->id), (string) $userA->id);

    expect(fn () => dispatchGetCareTeamMembership((string) $tenantA->id, (string) $facilityA->id, (string) Str::uuid7()))->toThrow(NotFound::class);

    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = careTeamFixture();
    test()->actingAs($userB);
    expect(fn () => dispatchGetCareTeamMembership((string) $tenantB->id, (string) $facilityB->id, $membershipId))->toThrow(NotFound::class);
});

test('an out-of-facility-scope membership -> NotFound (originating-facility-only; never a disclosure)', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facilityA->id, (string) $user->id);
    $membershipId = clinicalAddCareTeamMember((string) $tenant->id, (string) $facilityA->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id);
    Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('id', $membershipId)->update(['facility_id' => (string) Str::uuid7()]));

    expect(fn () => dispatchGetCareTeamMembership((string) $tenant->id, (string) $facilityA->id, $membershipId))->toThrow(NotFound::class);
});

test('a caller without clinical.care_team.read is denied (AuthorizationException)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture(['clinical.care_team.record']);
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $membershipId = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id);

    expect(fn () => dispatchGetCareTeamMembership((string) $tenant->id, (string) $facility->id, $membershipId))->toThrow(AuthorizationException::class);
});

test('GetCareTeamMembership rejects a malformed id at construction (IC §3)', function () {
    expect(fn () => new GetCareTeamMembership('not-a-uuid'))->toThrow(InvalidArgumentException::class);
});
