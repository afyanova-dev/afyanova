<?php

declare(strict_types=1);

use App\Domains\CareCoordination\Application\Command\RecordFollowUpOutcome;
use App\Domains\CareCoordination\Application\Command\ScheduleFollowUp;
use App\Domains\CareCoordination\Application\Query\GetFollowUpsDue;
use App\Domains\CareCoordination\Contracts\Event\FollowUpKept;
use App\Domains\CareCoordination\Contracts\Event\FollowUpMissed;
use App\Domains\CareCoordination\Contracts\Event\FollowUpScheduled;
use App\Domains\CareCoordination\Domain\Exception\FollowUpContextMismatch;
use App\Domains\CareCoordination\Domain\Exception\IllegalFollowUpTransition;
use App\Domains\CareCoordination\Domain\Exception\PatientNotEligibleForFollowUp;
use App\Domains\CareCoordination\Domain\Model\FollowUp;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function dispatchFollowUp(string $tenantId, ?string $facilityId, object $command): mixed
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

test('ScheduleFollowUp creates a PENDING follow-up, copies the encounter facility, emits a PHI-free event, audits', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    ['encounterId' => $encounterId, 'patientId' => $patientId] =
        seedEligibleReferralEncounter($t, $f, (string) $user->id);

    $followUpId = (string) Str::uuid7();
    dispatchFollowUp($t, $f, new ScheduleFollowUp(
        followUpId: $followUpId,
        sourceEncounterId: $encounterId,
        patientId: $patientId,
        actorId: (string) $user->id,
        dueFrom: now()->addWeek()->toDateString(),
        dueTo: now()->addWeeks(3)->toDateString(),
        reason: 'Wound review',
        instructions: 'Bring the discharge letter.',
    ));

    $followUp = TenantContextScope::run($t, $f, fn () => FollowUp::query()->find($followUpId));
    expect($followUp)->not->toBeNull()
        ->and($followUp->status)->toBe(FollowUp::STATUS_PENDING)
        ->and((string) $followUp->facility_id)->toBe($f)
        ->and((string) $followUp->patient_id)->toBe($patientId)
        ->and($followUp->reason)->toBe('Wound review')
        ->and((int) $followUp->version)->toBe(0);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', FollowUpScheduled::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->payload)->not->toContain('Wound review')
        ->and($outbox->payload)->not->toContain('discharge letter');

    $audit = Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('target_id', $followUpId)->where('action', 'care_coordination.followup_scheduled')->first());
    expect($audit)->not->toBeNull();
});

test('ScheduleFollowUp rejects a DECEASED patient and a patient/encounter mismatch', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    ['encounterId' => $encounterId, 'patientId' => $patientId] =
        seedEligibleReferralEncounter($t, $f, (string) $user->id);

    $deceased = TenantContextScope::run($t, null, fn () => (string) Patient::factory()->forTenant($t)->deceased()->create()->id);
    expect(fn () => dispatchFollowUp($t, $f, new ScheduleFollowUp(
        followUpId: (string) Str::uuid7(), sourceEncounterId: $encounterId, patientId: $deceased,
        actorId: (string) $user->id, dueFrom: now()->addWeek()->toDateString(), dueTo: now()->addWeeks(2)->toDateString(),
    )))->toThrow(PatientNotEligibleForFollowUp::class);

    $otherPatient = TenantContextScope::run($t, null, fn () => (string) Patient::factory()->forTenant($t)->create()->id);
    expect(fn () => dispatchFollowUp($t, $f, new ScheduleFollowUp(
        followUpId: (string) Str::uuid7(), sourceEncounterId: $encounterId, patientId: $otherPatient,
        actorId: (string) $user->id, dueFrom: now()->addWeek()->toDateString(), dueTo: now()->addWeeks(2)->toDateString(),
    )))->toThrow(FollowUpContextMismatch::class);
});

test('RecordFollowUpOutcome KEPT sets the resulting encounter and emits FollowUpKept; MISSED emits FollowUpMissed', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    ['encounterId' => $enc1, 'patientId' => $patientId] = seedEligibleReferralEncounter($t, $f, (string) $user->id);
    ['encounterId' => $enc2] = seedEligibleReferralEncounter($t, $f, (string) $user->id);

    $keptId = (string) Str::uuid7();
    dispatchFollowUp($t, $f, new ScheduleFollowUp(
        followUpId: $keptId, sourceEncounterId: $enc1, patientId: $patientId, actorId: (string) $user->id,
        dueFrom: now()->subWeek()->toDateString(), dueTo: now()->addWeek()->toDateString(),
    ));
    dispatchFollowUp($t, $f, new RecordFollowUpOutcome(
        followUpId: $keptId, actorId: (string) $user->id, outcome: FollowUp::STATUS_KEPT, resultingEncounterId: $enc2,
    ));

    $kept = TenantContextScope::run($t, $f, fn () => FollowUp::query()->find($keptId));
    expect($kept->status)->toBe(FollowUp::STATUS_KEPT)
        ->and((string) $kept->resulting_encounter_id)->toBe($enc2)
        ->and($kept->outcome_recorded_at)->not->toBeNull();

    $missedId = (string) Str::uuid7();
    dispatchFollowUp($t, $f, new ScheduleFollowUp(
        followUpId: $missedId, sourceEncounterId: $enc1, patientId: $patientId, actorId: (string) $user->id,
        dueFrom: now()->subWeeks(3)->toDateString(), dueTo: now()->subWeek()->toDateString(),
    ));
    dispatchFollowUp($t, $f, new RecordFollowUpOutcome(
        followUpId: $missedId, actorId: (string) $user->id, outcome: FollowUp::STATUS_MISSED,
    ));

    expect(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', FollowUpKept::class)->count()))->toBe(1)
        ->and(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', FollowUpMissed::class)->count()))->toBe(1);

    // a second outcome on a resolved follow-up is rejected
    expect(fn () => dispatchFollowUp($t, $f, new RecordFollowUpOutcome(
        followUpId: $keptId, actorId: (string) $user->id, outcome: FollowUp::STATUS_CANCELLED,
    )))->toThrow(IllegalFollowUpTransition::class);
});

test('RecordFollowUpOutcome KEPT without a resulting encounter is rejected in the command constructor', function () {
    expect(fn () => new RecordFollowUpOutcome(
        followUpId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        outcome: FollowUp::STATUS_KEPT,
    ))->toThrow(InvalidArgumentException::class);
});

test('CANCELLED / NOT_REQUIRED outcomes record no event, only an audit row', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['encounterId' => $enc, 'patientId' => $patientId] = seedEligibleReferralEncounter($t, $f, (string) $user->id);

    $id = (string) Str::uuid7();
    dispatchFollowUp($t, $f, new ScheduleFollowUp(
        followUpId: $id, sourceEncounterId: $enc, patientId: $patientId, actorId: (string) $user->id,
        dueFrom: now()->toDateString(), dueTo: now()->addWeek()->toDateString(),
    ));
    dispatchFollowUp($t, $f, new RecordFollowUpOutcome(
        followUpId: $id, actorId: (string) $user->id, outcome: FollowUp::STATUS_NOT_REQUIRED, note: 'Patient declined.',
    ));

    $fu = TenantContextScope::run($t, $f, fn () => FollowUp::query()->find($id));
    expect($fu->status)->toBe(FollowUp::STATUS_NOT_REQUIRED);

    $events = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->whereIn('event_class', [FollowUpKept::class, FollowUpMissed::class])->count());
    expect($events)->toBe(0);

    $audit = Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('target_id', $id)->where('action', 'care_coordination.followup_outcome_recorded')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->reason)->toBe('Patient declined.');
});

test('scheduling a follow-up requires care_coordination.followup.manage', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture(permissions: [
        'care_coordination.access', 'encounter.encounters.read', 'encounter.encounters.create',
        'encounter.encounters.update', 'patient.patients.read',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['encounterId' => $enc, 'patientId' => $patientId] = seedEligibleReferralEncounter($t, $f, (string) $user->id);

    expect(fn () => dispatchFollowUp($t, $f, new ScheduleFollowUp(
        followUpId: (string) Str::uuid7(), sourceEncounterId: $enc, patientId: $patientId, actorId: (string) $user->id,
        dueFrom: now()->addWeek()->toDateString(), dueTo: now()->addWeeks(2)->toDateString(),
    )))->toThrow(AuthorizationException::class);
});

test('GetFollowUpsDue returns PENDING follow-ups whose window overlaps the query range, at the facility', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['encounterId' => $enc, 'patientId' => $patientId] = seedEligibleReferralEncounter($t, $f, (string) $user->id);

    $inRange = (string) Str::uuid7();
    dispatchFollowUp($t, $f, new ScheduleFollowUp(
        followUpId: $inRange, sourceEncounterId: $enc, patientId: $patientId, actorId: (string) $user->id,
        dueFrom: now()->addDays(3)->toDateString(), dueTo: now()->addDays(10)->toDateString(),
    ));
    $outOfRange = (string) Str::uuid7();
    dispatchFollowUp($t, $f, new ScheduleFollowUp(
        followUpId: $outOfRange, sourceEncounterId: $enc, patientId: $patientId, actorId: (string) $user->id,
        dueFrom: now()->addMonths(3)->toDateString(), dueTo: now()->addMonths(4)->toDateString(),
    ));

    $due = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetFollowUpsDue(
        facilityId: $f,
        from: now()->toDateString(),
        to: now()->addWeeks(2)->toDateString(),
    )));

    expect($due)->toHaveCount(1)
        ->and($due[0]->followUpId)->toBe($inRange);
});
