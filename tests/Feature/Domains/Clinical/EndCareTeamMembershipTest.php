<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\EndCareTeamMembership;
use App\Domains\Clinical\Contracts\Event\CareTeamMemberRemoved;
use App\Domains\Clinical\Contracts\Event\ProviderOfRecordChanged;
use App\Domains\Clinical\Domain\Exception\IllegalCareTeamMembershipTransition;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.6) gate — EndCareTeamMembership end-to-end (IC §5; ADR-142 D-I /
 | D-J / D-N / D-R / D-S). Subject-level auth + originating-facility refinement;
 | ACTIVE -> ENDED terminal; optional end_reason on the ROW only; clears
 | provider-of-record; NO PatientApi / EncounterApi call.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/care_team.php';

function endMembership(string $tenantId, ?string $facilityId, string $membershipId, string $actorId, ?string $reason = null): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
        new EndCareTeamMembership($membershipId, $actorId, $reason)
    ));
}

test('ACTIVE -> ENDED: active_to / removed_by set, version bumped, CareTeamMemberRemoved + audit; end_reason on the ROW only', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $membershipId = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id);

    endMembership((string) $tenant->id, (string) $facility->id, $membershipId, (string) $user->id, '  handover to inpatient team  ');

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('id', $membershipId)->first());
    expect($row->active_to)->not->toBeNull()
        ->and($row->removed_by_user_id)->toBe((string) $user->id)
        ->and($row->end_reason)->toBe('handover to inpatient team') // trimmed, ROW only
        ->and((int) $row->version)->toBe(1);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', CareTeamMemberRemoved::class)->first());
    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['wasProviderOfRecord'])->toBeFalse()
        ->and($payload)->not->toHaveKey('endReason')
        ->and($payload)->not->toHaveKey('reason')
        ->and(json_encode($payload))->not->toContain('handover to inpatient team');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.care_team.member_removed')->first());
    expect($audit->reason)->toBeNull()
        ->and($audit->target_id)->toBe($membershipId)
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
});

test('ending the provider-of-record clears the designation and emits ProviderOfRecordChanged (new = null)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $staffUserId = makeStaffUser((string) $tenant->id);
    $membershipId = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffUserId, (string) $user->id, ['isProviderOfRecord' => true]);

    endMembership((string) $tenant->id, (string) $facility->id, $membershipId, (string) $user->id);

    expect((bool) Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('id', $membershipId)->value('is_provider_of_record')))->toBeFalse()
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')
            ->where('encounter_id', $encounterId)->where('is_provider_of_record', true)->whereNull('active_to')->count()))->toBe(0);

    $por = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', ProviderOfRecordChanged::class)->orderByDesc('id')->first());
    $payload = json_decode((string) $por->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['previousProviderStaffUserId'])->toBe($staffUserId)
        ->and($payload['newProviderStaffUserId'])->toBeNull();
});

test('ending an already-ENDED membership -> IllegalCareTeamMembershipTransition (409, no reactivation)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $membershipId = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id);
    endMembership((string) $tenant->id, (string) $facility->id, $membershipId, (string) $user->id);

    try {
        endMembership((string) $tenant->id, (string) $facility->id, $membershipId, (string) $user->id);
        test()->fail('expected IllegalCareTeamMembershipTransition');
    } catch (IllegalCareTeamMembershipTransition $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_care_team_membership_transition');
    }
});

test('End is NOT patient-state- or encounter-state-gated — works on a completed encounter / merged patient', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $membershipId = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id);

    Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->update(['status' => 'MERGED']));
    Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->update(['status' => 'CANCELLED']));

    endMembership((string) $tenant->id, (string) $facility->id, $membershipId, (string) $user->id, 'retrospective cleanup');
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('id', $membershipId)->value('active_to')))->not->toBeNull();
});

test('an unknown membership -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    expect(fn () => endMembership((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);
});

test('an out-of-facility-scope membership -> subject-level policy denial', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facilityA->id, (string) $user->id);
    $membershipId = clinicalAddCareTeamMember((string) $tenant->id, (string) $facilityA->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id);
    Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('id', $membershipId)->update(['facility_id' => (string) Str::uuid7()]));

    expect(fn () => endMembership((string) $tenant->id, (string) $facilityA->id, $membershipId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('EndCareTeamMembership rejects a malformed id at construction (IC §3)', function () {
    expect(fn () => new EndCareTeamMembership('not-a-uuid', (string) Str::uuid7()))->toThrow(InvalidArgumentException::class);
});
