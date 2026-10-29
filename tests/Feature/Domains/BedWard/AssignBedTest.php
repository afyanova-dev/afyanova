<?php

declare(strict_types=1);

use App\Domains\BedWard\Application\Command\AssignBed;
use App\Domains\BedWard\Contracts\Event\BedAssigned;
use App\Domains\BedWard\Contracts\Event\BedReservationFulfilled;
use App\Domains\BedWard\Domain\Exception\BedNotAvailable;
use App\Domains\BedWard\Domain\Exception\EncounterAlreadyHasActiveOccupancy;
use App\Domains\BedWard\Domain\Exception\EncounterNotEligibleForBedAssignment;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Domains\BedWard\Domain\Model\BedReservation;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('it assigns a bed: bed becomes OCCUPIED, occupancy record created, event on outbox, audit written', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id);

    ['encounterId' => $encounterId, 'patientId' => $patientId] =
        seedActiveInpatientEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $occupancyId = (string) Str::uuid7();
    $command = new AssignBed(
        occupancyId: $occupancyId,
        bedId: (string) $bed->id,
        encounterId: $encounterId,
        actorId: (string) $user->id,
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command));

    // Bed status changed to OCCUPIED
    $freshBed = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Bed::query()->find($bed->id));
    expect($freshBed)->not->toBeNull()
        ->and($freshBed->status)->toBe(Bed::STATUS_OCCUPIED)
        ->and((int) $freshBed->version)->toBe(1);

    // Active occupancy record created
    $occ = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => BedOccupancy::query()->find($occupancyId));
    expect($occ)->not->toBeNull()
        ->and((string) $occ->bed_id)->toBe((string) $bed->id)
        ->and((string) $occ->encounter_id)->toBe($encounterId)
        ->and((string) $occ->patient_id)->toBe($patientId)
        ->and((string) $occ->assigned_by_user_id)->toBe((string) $user->id)
        ->and($occ->status)->toBe(BedOccupancy::STATUS_ACTIVE)
        ->and($occ->end_reason)->toBeNull()
        ->and($occ->ended_at)->toBeNull()
        ->and((int) $occ->version)->toBe(0);

    // Outbox event
    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', BedAssigned::class)
        ->first());

    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('bed_ward.BedAssigned');

    $payload = json_decode((string) $outbox->payload, true);
    expect($payload['occupancyId'])->toBe($occupancyId)
        ->and($payload['bedId'])->toBe((string) $bed->id)
        ->and($payload['encounterId'])->toBe($encounterId);

    // Audit log for bed assignment
    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('target_id', $occupancyId)
        ->where('action', 'bed_ward.bed_assigned')
        ->first());
    expect($audit)->not->toBeNull();
});

test('it rejects AssignBed if encounter is not ACTIVE (e.g. ARRIVED)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id);

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id);
    $encounterId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user): void {
        // Create encounter but do NOT start it -> status is ARRIVED
        app(CommandBus::class)->dispatch(new CreateEncounter(
            encounterId: $encounterId,
            patientId: $patientId,
            actorId: (string) $user->id,
            encounterType: EncounterType::Inpatient,
            planned: false,
        ));
    });

    $command = new AssignBed(
        occupancyId: (string) Str::uuid7(),
        bedId: (string) $bed->id,
        encounterId: $encounterId,
        actorId: (string) $user->id,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(EncounterNotEligibleForBedAssignment::class);
});

test('it rejects AssignBed if encounter type is not INPATIENT or MATERNITY', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture(capabilities: ['INPATIENT_CARE', 'OUTPATIENT_CARE']);

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id);

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id);
    $encounterId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user): void {

        app(CommandBus::class)->dispatch(new CreateEncounter(
            encounterId: $encounterId,
            patientId: $patientId,
            actorId: (string) $user->id,
            encounterType: EncounterType::Outpatient,
            planned: false,
        ));

        app(CommandBus::class)->dispatch(new StartEncounter(
            encounterId: $encounterId,
            actorId: (string) $user->id,
        ));
    });

    $command = new AssignBed(
        occupancyId: (string) Str::uuid7(),
        bedId: (string) $bed->id,
        encounterId: $encounterId,
        actorId: (string) $user->id,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(EncounterNotEligibleForBedAssignment::class);
});

test('it rejects AssignBed if bed is not in AVAILABLE status', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'status' => Bed::STATUS_CLEANING,
    ]);

    ['encounterId' => $encounterId] =
        seedActiveInpatientEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $command = new AssignBed(
        occupancyId: (string) Str::uuid7(),
        bedId: (string) $bed->id,
        encounterId: $encounterId,
        actorId: (string) $user->id,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(BedNotAvailable::class);
});

test('it rejects AssignBed if encounter already has an active bed occupancy', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed1 = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, ['label' => 'B-01']);
    $bed2 = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, ['label' => 'B-02']);

    ['encounterId' => $encounterId] =
        seedActiveInpatientEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    // Assign first bed
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new AssignBed(
        occupancyId: (string) Str::uuid7(),
        bedId: (string) $bed1->id,
        encounterId: $encounterId,
        actorId: (string) $user->id,
    )));

    // Try assigning second bed to same encounter
    $cmd2 = new AssignBed(
        occupancyId: (string) Str::uuid7(),
        bedId: (string) $bed2->id,
        encounterId: $encounterId,
        actorId: (string) $user->id,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($cmd2)))
        ->toThrow(EncounterAlreadyHasActiveOccupancy::class);
});

test('AssignBed fulfills a matching HELD reservation atomically (ADR-149 D-E)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'status' => Bed::STATUS_RESERVED,
    ]);
    ['encounterId' => $encounterId] = seedActiveInpatientEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $reservation = createBedReservationFixture((string) $tenant->id, (string) $facility->id, (string) $bed->id, [
        'status' => BedReservation::STATUS_HELD,
        'encounter_id' => $encounterId,
        'hold_until' => now()->addHours(2),
    ]);

    $occupancyId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new AssignBed(
        occupancyId: $occupancyId,
        bedId: (string) $bed->id,
        encounterId: $encounterId,
        actorId: (string) $user->id,
    )));

    $freshBed = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Bed::query()->find($bed->id));
    expect($freshBed->status)->toBe(Bed::STATUS_OCCUPIED);

    $fulfilled = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => BedReservation::query()->find($reservation->id));
    expect($fulfilled->status)->toBe(BedReservation::STATUS_FULFILLED);

    $occ = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => BedOccupancy::query()->find($occupancyId));
    expect($occ)->not->toBeNull()->and($occ->status)->toBe(BedOccupancy::STATUS_ACTIVE);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', BedReservationFulfilled::class)
        ->first());
    expect($outbox)->not->toBeNull();
});

test('AssignBed rejects a RESERVED bed whose HELD reservation is for a different encounter', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'status' => Bed::STATUS_RESERVED,
    ]);
    ['encounterId' => $reservedForEncounterId] = seedActiveInpatientEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    ['encounterId' => $otherEncounterId] = seedActiveInpatientEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    createBedReservationFixture((string) $tenant->id, (string) $facility->id, (string) $bed->id, [
        'status' => BedReservation::STATUS_HELD,
        'encounter_id' => $reservedForEncounterId,
        'hold_until' => now()->addHours(2),
    ]);

    $command = new AssignBed(
        occupancyId: (string) Str::uuid7(),
        bedId: (string) $bed->id,
        encounterId: $otherEncounterId,
        actorId: (string) $user->id,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(BedNotAvailable::class);
});

test('AssignBed self-heals a RESERVED bed whose HELD reservation has lapsed, then assigns normally (ADR-149 D-C)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'status' => Bed::STATUS_RESERVED,
    ]);
    ['encounterId' => $staleEncounterId] = seedActiveInpatientEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    ['encounterId' => $newEncounterId] = seedActiveInpatientEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $staleReservation = createBedReservationFixture((string) $tenant->id, (string) $facility->id, (string) $bed->id, [
        'status' => BedReservation::STATUS_HELD,
        'encounter_id' => $staleEncounterId,
        'hold_until' => now()->subMinute(),
    ]);

    $occupancyId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new AssignBed(
        occupancyId: $occupancyId,
        bedId: (string) $bed->id,
        encounterId: $newEncounterId,
        actorId: (string) $user->id,
    )));

    $expired = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => BedReservation::query()->find($staleReservation->id));
    expect($expired->status)->toBe(BedReservation::STATUS_EXPIRED);

    $freshBed = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Bed::query()->find($bed->id));
    expect($freshBed->status)->toBe(Bed::STATUS_OCCUPIED);

    $occ = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => BedOccupancy::query()->find($occupancyId));
    expect($occ)->not->toBeNull()->and($occ->encounter_id)->toBe($newEncounterId);
});
