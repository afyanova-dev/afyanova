<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\AddCareTeamMember;
use App\Domains\Clinical\Application\Command\EndCareTeamMembership;
use App\Domains\Clinical\Contracts\Event\CareTeamMemberAdded;
use App\Domains\Clinical\Contracts\Event\ProviderOfRecordChanged;
use App\Domains\Clinical\Domain\Exception\CareTeamMemberNotStaff;
use App\Domains\Clinical\Domain\Exception\DuplicateActiveCareTeamMembership;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForCareTeam;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\ProviderOfRecordConflict;
use App\Domains\Clinical\Domain\ValueObject\TeamRole;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.6) gate — AddCareTeamMember end-to-end through the real CommandBus
 | (IC §5; ADR-142 D-E … D-K / D-N / D-R / D-S): authorize ->
 | EncounterApi::getEncounterContext -> per-encounter-facility refinement ->
 | encounter-state gate {PLANNED, ARRIVED, ACTIVE, COMPLETED} ->
 | PatientApi::getPatientSummary -> IdentityApi::isStaff -> duplicate / PoR
 | pre-checks -> ACTIVE membership -> versioned INSERT -> CareTeamMemberAdded
 | (+ ProviderOfRecordChanged) outbox + clinical.care_team.member_added audit.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/care_team.php';

function factoryEncounter(string $tenantId, string $facilityId, string $patientId, EncounterStatus $status): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)->forPatient($patientId)
        ->withStatus($status)
        ->create(['disposition' => $status === EncounterStatus::Completed ? 'DISCHARGED' : null])->id);
}

// ── happy path ─────────────────────────────────────────────────────

test('an active encounter -> an ACTIVE membership: origin context from the encounter, version 0, CareTeamMemberAdded + audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $staffUserId = makeStaffUser((string) $tenant->id);

    $membershipId = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffUserId, (string) $user->id, [
        'teamRole' => TeamRole::CONSULTANT,
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('id', $membershipId)->first());
    expect($row)->not->toBeNull()
        ->and($row->tenant_id)->toBe((string) $tenant->id)
        ->and($row->facility_id)->toBe((string) $facility->id)   // ORIGINATING facility, from the encounter context
        ->and($row->encounter_id)->toBe($encounterId)
        ->and($row->patient_id)->toBe($patientId)                // from the encounter context, not caller-controlled
        ->and($row->staff_user_id)->toBe($staffUserId)
        ->and($row->team_role)->toBe('CONSULTANT')
        ->and((bool) $row->is_provider_of_record)->toBeFalse()
        ->and($row->active_to)->toBeNull()
        ->and($row->active_from)->not->toBeNull()
        ->and($row->added_by_user_id)->toBe((string) $user->id)
        ->and($row->removed_by_user_id)->toBeNull()
        ->and($row->end_reason)->toBeNull()
        ->and((int) $row->version)->toBe(0);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', CareTeamMemberAdded::class)->first());
    expect($outbox)->not->toBeNull();
    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['teamRole'])->toBe('CONSULTANT')
        ->and($payload['isProviderOfRecord'])->toBeFalse()
        ->and($payload['staffUserId'])->toBe($staffUserId)
        ->and($payload)->not->toHaveKey('endReason')
        ->and($payload)->not->toHaveKey('reason');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.care_team.member_added')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->reason)->toBeNull()
        ->and($audit->target_id)->toBe($membershipId)
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
});

test('the same staff user MAY hold multiple roles on one encounter', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $staffUserId = makeStaffUser((string) $tenant->id);

    clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffUserId, (string) $user->id, ['teamRole' => TeamRole::ATTENDING]);
    clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffUserId, (string) $user->id, ['teamRole' => TeamRole::COORDINATOR]);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('encounter_id', $encounterId)->count()))->toBe(2);
});

// ── provider-of-record at add ──────────────────────────────────────

test('AddCareTeamMember(isProviderOfRecord: true) with no current PoR -> designates it and emits ProviderOfRecordChanged (previous = null)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $staffUserId = makeStaffUser((string) $tenant->id);

    $membershipId = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffUserId, (string) $user->id, [
        'isProviderOfRecord' => true,
    ]);

    expect((bool) Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('id', $membershipId)->value('is_provider_of_record')))->toBeTrue();

    $por = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', ProviderOfRecordChanged::class)->first());
    expect($por)->not->toBeNull();
    $payload = json_decode((string) $por->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['previousProviderStaffUserId'])->toBeNull()
        ->and($payload['newProviderStaffUserId'])->toBe($staffUserId);
});

test('AddCareTeamMember(isProviderOfRecord: true) when the encounter already has a PoR -> ProviderOfRecordConflict (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id, ['isProviderOfRecord' => true]);

    try {
        clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id, ['isProviderOfRecord' => true]);
        test()->fail('expected ProviderOfRecordConflict');
    } catch (ProviderOfRecordConflict $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('provider_of_record_conflict');
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')
        ->where('encounter_id', $encounterId)->where('is_provider_of_record', true)->count()))->toBe(1);
});

// ── encounter-state gate (ADR-142 D-K — PLANNED PERMITTED) ─────────

test('PLANNED / ARRIVED / ACTIVE / COMPLETED all permit adding a care-team member', function (EncounterStatus $status) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    $encounterId = factoryEncounter((string) $tenant->id, (string) $facility->id, $patientId, $status);

    $membershipId = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('id', $membershipId)->exists()))->toBeTrue();
})->with([
    'PLANNED' => EncounterStatus::Planned,
    'ARRIVED' => EncounterStatus::Arrived,
    'ACTIVE' => EncounterStatus::Active,
    'COMPLETED' => EncounterStatus::Completed,
]);

test('CANCELLED / ABANDONED / LEFT_WITHOUT_BEING_SEEN / TRANSFERRED reject adding a care-team member (409)', function (EncounterStatus $status) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    $encounterId = factoryEncounter((string) $tenant->id, (string) $facility->id, $patientId, $status);

    expect(fn () => clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id))
        ->toThrow(EncounterNotEligibleForCareTeam::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->count()))->toBe(0);
})->with([
    'CANCELLED' => EncounterStatus::Cancelled,
    'ABANDONED' => EncounterStatus::Abandoned,
    'LEFT_WITHOUT_BEING_SEEN' => EncounterStatus::LeftWithoutBeingSeen,
    'TRANSFERRED' => EncounterStatus::Transferred,
]);

// ── patient-state gate (ADR-142 D-L) ─────────────────────────────

test('a provisional ACTIVE patient is permitted (ADR-119)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id, ['provisional' => true])->patientId;
    $encounterId = factoryEncounter((string) $tenant->id, (string) $facility->id, $patientId, EncounterStatus::Active);

    $membershipId = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('id', $membershipId)->exists()))->toBeTrue();
});

test('an INACTIVE / DECEASED / MERGED patient rejects adding a care-team member (409)', function (string $patientStatus) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    $encounterId = factoryEncounter((string) $tenant->id, (string) $facility->id, $patientId, EncounterStatus::Active);
    Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->update(['status' => $patientStatus]));

    expect(fn () => clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id))
        ->toThrow(PatientNotEligibleForClinicalRecord::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->count()))->toBe(0);
})->with(['INACTIVE', 'DECEASED', 'MERGED']);

// ── staff validation (ADR-142 D-E) ───────────────────────────────

test('a non-staff user cannot be added to the care team (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    try {
        clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, makeNonStaffUser(), (string) $user->id);
        test()->fail('expected CareTeamMemberNotStaff');
    } catch (CareTeamMemberNotStaff $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('care_team_member_not_staff');
    }
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->count()))->toBe(0);
});

// ── duplicate active membership (ADR-142 D-D) ─────────────────────

test('a duplicate active (encounter, staff, role) membership is rejected (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $staffUserId = makeStaffUser((string) $tenant->id);

    clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffUserId, (string) $user->id, ['teamRole' => TeamRole::NURSE]);

    try {
        clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffUserId, (string) $user->id, ['teamRole' => TeamRole::NURSE]);
        test()->fail('expected DuplicateActiveCareTeamMembership');
    } catch (DuplicateActiveCareTeamMembership $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('duplicate_active_care_team_membership');
    }
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->count()))->toBe(1);
});

test('historical recurrence after ending IS allowed — same (encounter, staff, role) re-added', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $staffUserId = makeStaffUser((string) $tenant->id);

    $first = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffUserId, (string) $user->id, ['teamRole' => TeamRole::NURSE]);
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new EndCareTeamMembership($first, (string) $user->id)
    ));

    $second = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffUserId, (string) $user->id, ['teamRole' => TeamRole::NURSE]);

    expect($second)->not->toBe($first)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('encounter_id', $encounterId)->count()))->toBe(2);
});

// ── authorization + isolation ────────────────────────────────────

test('a caller without clinical.care_team.record is denied (AuthorizationException)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture(['clinical.access', 'patient.patients.read', 'encounter.encounters.read', 'patient.patients.create', 'encounter.encounters.create', 'encounter.encounters.update']);
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('an out-of-facility-scope encounter -> NotFound (never an authorization disclosure)', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = careTeamFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facilityA->id, (string) $user->id);
    $facilityB = (string) Str::uuid7();
    Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->update(['facility_id' => $facilityB]));

    expect(fn () => clinicalAddCareTeamMember((string) $tenant->id, (string) $facilityA->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id))
        ->toThrow(NotFound::class);
});

test('an unknown encounter -> NotFound; nothing persisted', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();

    expect(fn () => clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), makeStaffUser((string) $tenant->id), (string) $user->id))
        ->toThrow(NotFound::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->count()))->toBe(0);
});

test('AddCareTeamMember rejects an unknown team_role at construction (IC §3)', function () {
    expect(fn () => new AddCareTeamMember(
        careTeamMembershipId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        staffUserId: (string) Str::uuid7(),
        teamRole: 'SURGEON',
    ))->toThrow(InvalidArgumentException::class);
});
