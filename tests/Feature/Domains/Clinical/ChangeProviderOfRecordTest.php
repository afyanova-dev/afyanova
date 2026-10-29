<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\ChangeProviderOfRecord;
use App\Domains\Clinical\Contracts\Event\ProviderOfRecordChanged;
use App\Domains\Clinical\Domain\Exception\ProviderOfRecordConflict;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.6) gate — ChangeProviderOfRecord end-to-end (IC §5; ADR-142 D-C /
 | D-G / D-H / D-J / D-N / D-R / D-S). ONE parameterized command for first
 | assignment AND hand-off; NO `reason` parameter (reconciliation R-1); atomic
 | two-instance transition; the DB partial unique index is authoritative.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/care_team.php';

function changePoR(string $tenantId, ?string $facilityId, string $encounterId, string $newStaffUserId, string $actorId): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
        new ChangeProviderOfRecord($encounterId, $actorId, $newStaffUserId)
    ));
}

test('the command has NO reason parameter (reconciliation R-1)', function () {
    $params = (new ReflectionClass(ChangeProviderOfRecord::class))->getConstructor()->getParameters();
    $names = array_map(fn (ReflectionParameter $p): string => $p->getName(), $params);

    expect($names)->toBe(['encounterId', 'actorId', 'newProviderStaffUserId', 'idempotencyKey'])
        ->and($names)->not->toContain('reason');
});

test('first assignment (no current PoR) -> the member becomes PoR; ProviderOfRecordChanged (previous = null); audit; no reason', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $staffUserId = makeStaffUser((string) $tenant->id);
    $membershipId = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffUserId, (string) $user->id);

    changePoR((string) $tenant->id, (string) $facility->id, $encounterId, $staffUserId, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('id', $membershipId)->first());
    expect((bool) $row->is_provider_of_record)->toBeTrue()
        ->and($row->active_to)->toBeNull()          // still an active member
        ->and((int) $row->version)->toBe(1);

    $por = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', ProviderOfRecordChanged::class)->first());
    $payload = json_decode((string) $por->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['previousProviderStaffUserId'])->toBeNull()
        ->and($payload['newProviderStaffUserId'])->toBe($staffUserId)
        ->and($payload['newProviderMembershipId'])->toBe($membershipId)
        ->and($payload)->not->toHaveKey('reason');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.care_team.provider_of_record_changed')->first());
    expect($audit->reason)->toBeNull()
        ->and($audit->target_id)->toBe($encounterId)
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
});

test('hand-off A -> B: A loses PoR but stays an active member; B gains PoR; ONE ProviderOfRecordChanged carrying both', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $staffA = makeStaffUser((string) $tenant->id);
    $staffB = makeStaffUser((string) $tenant->id);
    $mA = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffA, (string) $user->id, ['isProviderOfRecord' => true]);
    $mB = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffB, (string) $user->id);

    changePoR((string) $tenant->id, (string) $facility->id, $encounterId, $staffB, (string) $user->id);

    $rowA = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('id', $mA)->first());
    $rowB = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('id', $mB)->first());
    expect((bool) $rowA->is_provider_of_record)->toBeFalse()
        ->and($rowA->active_to)->toBeNull()            // A remains an active team member
        ->and((bool) $rowB->is_provider_of_record)->toBeTrue()
        ->and($rowB->active_to)->toBeNull();

    // exactly one active PoR on the encounter
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')
        ->where('encounter_id', $encounterId)->where('is_provider_of_record', true)->whereNull('active_to')->count()))->toBe(1);

    $latest = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ProviderOfRecordChanged::class)->orderByDesc('id')->first());
    $payload = json_decode((string) $latest->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['previousProviderStaffUserId'])->toBe($staffA)
        ->and($payload['previousProviderMembershipId'])->toBe($mA)
        ->and($payload['newProviderStaffUserId'])->toBe($staffB)
        ->and($payload['newProviderMembershipId'])->toBe($mB);
});

test('the incoming provider-of-record must already be an active member (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id);

    try {
        changePoR((string) $tenant->id, (string) $facility->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id);
        test()->fail('expected ProviderOfRecordConflict');
    } catch (ProviderOfRecordConflict $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('provider_of_record_conflict');
    }
});

test('changing PoR to the person who is already PoR -> 409 (not idempotent)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $staffUserId = makeStaffUser((string) $tenant->id);
    clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffUserId, (string) $user->id, ['isProviderOfRecord' => true]);

    expect(fn () => changePoR((string) $tenant->id, (string) $facility->id, $encounterId, $staffUserId, (string) $user->id))
        ->toThrow(ProviderOfRecordConflict::class);
});

test('an encounter with no care-team memberships -> NotFound (non-disclosing)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => changePoR((string) $tenant->id, (string) $facility->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id))
        ->toThrow(NotFound::class);
});

test('an out-of-facility-scope actor -> NotFound (never an authorization disclosure)', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facilityA->id, (string) $user->id);
    $staffUserId = makeStaffUser((string) $tenant->id);
    $membershipId = clinicalAddCareTeamMember((string) $tenant->id, (string) $facilityA->id, $encounterId, $staffUserId, (string) $user->id);
    Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('id', $membershipId)->update(['facility_id' => (string) Str::uuid7()]));

    expect(fn () => changePoR((string) $tenant->id, (string) $facilityA->id, $encounterId, $staffUserId, (string) $user->id))
        ->toThrow(NotFound::class);
});

test('a caller without clinical.care_team.record is denied (AuthorizationException)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $staffUserId = makeStaffUser((string) $tenant->id);
    clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffUserId, (string) $user->id);

    // strip the record key from the role
    Tenancy::run((string) $tenant->id, function () use ($user): void {
        $roleId = DB::table('idn_access_assignments')->where('user_id', $user->id)->value('role_id');
        $permId = DB::table('idn_permissions')->where('key', 'clinical.care_team.record')->value('id');
        DB::table('idn_permission_role')->where('role_id', $roleId)->where('permission_id', $permId)->delete();
    });

    expect(fn () => changePoR((string) $tenant->id, (string) $facility->id, $encounterId, $staffUserId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('ChangeProviderOfRecord rejects a malformed id at construction (IC §3)', function () {
    expect(fn () => new ChangeProviderOfRecord('not-a-uuid', (string) Str::uuid7(), (string) Str::uuid7()))
        ->toThrow(InvalidArgumentException::class);
});
