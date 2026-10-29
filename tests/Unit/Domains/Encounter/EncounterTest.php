<?php

declare(strict_types=1);

use App\Domains\Encounter\Contracts\Event\EncounterAdmitted;
use App\Domains\Encounter\Contracts\Event\EncounterArrived;
use App\Domains\Encounter\Contracts\Event\EncounterCompleted;
use App\Domains\Encounter\Contracts\Event\EncounterCreated;
use App\Domains\Encounter\Contracts\Event\EncounterDispositionChanged;
use App\Domains\Encounter\Contracts\Event\EncounterLocationChanged;
use App\Domains\Encounter\Contracts\Event\EncounterStarted;
use App\Domains\Encounter\Contracts\Event\EncounterTransferred;
use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Exception\IllegalEncounterTransition;
use App\Domains\Encounter\Domain\Model\Encounter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 5 (increments E.1 + E.2) unit gate — the Encounter aggregate
 | (11-encounter-domain.md §8, §19, §177; ADR-101, ADR-104, ADR-121, ADR-130).
 | Pure in-memory assertions — no database.
 */

/**
 * @param  array<string, mixed>  $overrides
 */
function openEncounter(array $overrides = []): Encounter
{
    return Encounter::open(
        id: $overrides['id'] ?? (string) Str::uuid7(),
        tenantId: $overrides['tenantId'] ?? (string) Str::uuid7(),
        facilityId: $overrides['facilityId'] ?? (string) Str::uuid7(),
        patientId: $overrides['patientId'] ?? (string) Str::uuid7(),
        encounterNumber: $overrides['encounterNumber'] ?? 'ENC-00000001',
        type: $overrides['type'] ?? EncounterType::Outpatient,
        status: $overrides['status'] ?? EncounterStatus::Arrived,
        departmentId: $overrides['departmentId'] ?? null,
        unitId: $overrides['unitId'] ?? null,
        createdByUserId: $overrides['createdByUserId'] ?? (string) Str::uuid7(),
        source: $overrides['source'] ?? 'RECEPTION',
    );
}

test('open() builds an ARRIVED encounter with version 0, arrived_at set, and no disposition', function () {
    $encounter = openEncounter();

    expect($encounter->status)->toBe(EncounterStatus::Arrived)
        ->and($encounter->encounter_type)->toBe(EncounterType::Outpatient)
        ->and($encounter->disposition)->toBeNull()
        ->and($encounter->arrived_at)->not->toBeNull()
        ->and($encounter->started_at)->toBeNull()
        ->and($encounter->admitted_at)->toBeNull()
        ->and($encounter->completed_at)->toBeNull()
        ->and((int) $encounter->version)->toBe(0)
        ->and($encounter->encounter_number)->toBe('ENC-00000001');
});

test('open() with PLANNED status leaves arrived_at null', function () {
    $encounter = openEncounter(['status' => EncounterStatus::Planned]);

    expect($encounter->status)->toBe(EncounterStatus::Planned)
        ->and($encounter->arrived_at)->toBeNull();
});

test('open() persists the coarse care-context location when supplied', function () {
    $departmentId = (string) Str::uuid7();
    $unitId = (string) Str::uuid7();

    $encounter = openEncounter(['departmentId' => $departmentId, 'unitId' => $unitId]);

    expect($encounter->department_id)->toBe($departmentId)
        ->and($encounter->unit_id)->toBe($unitId);
});

test('open() records exactly one PHI-free EncounterCreated event', function () {
    $tenantId = (string) Str::uuid7();
    $facilityId = (string) Str::uuid7();
    $patientId = (string) Str::uuid7();
    $encounterId = (string) Str::uuid7();

    $encounter = openEncounter([
        'id' => $encounterId,
        'tenantId' => $tenantId,
        'facilityId' => $facilityId,
        'patientId' => $patientId,
        'type' => EncounterType::Emergency,
        'status' => EncounterStatus::Arrived,
    ]);

    $events = $encounter->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(EncounterCreated::class);

    /** @var EncounterCreated $event */
    $event = $events[0];
    expect($event->tenantId)->toBe($tenantId)
        ->and($event->facilityId)->toBe($facilityId)
        ->and($event->encounterId)->toBe($encounterId)
        ->and($event->patientId)->toBe($patientId)
        ->and($event->encounterNumber)->toBe('ENC-00000001')
        ->and($event->encounterType)->toBe('EMERGENCY')
        ->and($event->status)->toBe('ARRIVED')
        ->and($event->eventId)->not->toBeEmpty();

    // PHI-free — the event carries ids + coded values only
    $payload = json_encode($event);
    expect($payload)->not->toContain('RECEPTION'); // provenance is not on the event
});

test('EncounterCreated is a final readonly event (IC §7.0/§7.1)', function () {
    $reflection = new ReflectionClass(EncounterCreated::class);
    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});

test('releaseEvents() drains the buffer — a second call is empty', function () {
    $encounter = openEncounter();

    expect($encounter->releaseEvents())->toHaveCount(1)
        ->and($encounter->releaseEvents())->toHaveCount(0);
});

// ── E.2 — recordArrival() ────────────────────────────────────────────

test('recordArrival() moves PLANNED -> ARRIVED, sets arrived_at, and touches nothing else', function () {
    $encounter = openEncounter([
        'status' => EncounterStatus::Planned,
        'type' => EncounterType::Emergency,
        'departmentId' => (string) Str::uuid7(),
        'unitId' => (string) Str::uuid7(),
    ]);
    $encounter->releaseEvents(); // drop EncounterCreated

    $before = [
        'encounter_type' => $encounter->encounter_type,
        'patient_id' => $encounter->patient_id,
        'tenant_id' => $encounter->tenant_id,
        'facility_id' => $encounter->facility_id,
        'encounter_number' => $encounter->encounter_number,
        'department_id' => $encounter->department_id,
        'unit_id' => $encounter->unit_id,
        'disposition' => $encounter->disposition,
        'started_at' => $encounter->started_at,
        'admitted_at' => $encounter->admitted_at,
        'completed_at' => $encounter->completed_at,
    ];

    expect($encounter->arrived_at)->toBeNull();

    $encounter->recordArrival();

    expect($encounter->status)->toBe(EncounterStatus::Arrived)
        ->and($encounter->arrived_at)->not->toBeNull();

    foreach ($before as $key => $value) {
        expect($encounter->{$key})->toBe($value);
    }
});

test('recordArrival() records exactly one PHI-free EncounterArrived event', function () {
    $tenantId = (string) Str::uuid7();
    $facilityId = (string) Str::uuid7();
    $patientId = (string) Str::uuid7();
    $encounterId = (string) Str::uuid7();

    $encounter = openEncounter([
        'id' => $encounterId, 'tenantId' => $tenantId, 'facilityId' => $facilityId,
        'patientId' => $patientId, 'status' => EncounterStatus::Planned,
    ]);
    $encounter->releaseEvents();

    $encounter->recordArrival();
    $events = $encounter->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(EncounterArrived::class);

    /** @var EncounterArrived $event */
    $event = $events[0];
    expect($event->tenantId)->toBe($tenantId)
        ->and($event->facilityId)->toBe($facilityId)
        ->and($event->encounterId)->toBe($encounterId)
        ->and($event->patientId)->toBe($patientId)
        ->and($event->eventId)->not->toBeEmpty()
        ->and($event->occurredAt)->not->toBeEmpty();

    $reflection = new ReflectionClass(EncounterArrived::class);
    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});

// ── E.3 — start() ───────────────────────────────────────────────────

test('start() moves ARRIVED -> ACTIVE, sets started_at, leaves arrived_at and everything else unchanged', function () {
    $encounter = openEncounter([
        'status' => EncounterStatus::Arrived,
        'type' => EncounterType::Emergency,
        'departmentId' => (string) Str::uuid7(),
        'unitId' => (string) Str::uuid7(),
    ]);
    $encounter->releaseEvents(); // drop EncounterCreated

    $before = [
        'arrived_at' => $encounter->arrived_at,
        'encounter_type' => $encounter->encounter_type,
        'patient_id' => $encounter->patient_id,
        'tenant_id' => $encounter->tenant_id,
        'facility_id' => $encounter->facility_id,
        'encounter_number' => $encounter->encounter_number,
        'department_id' => $encounter->department_id,
        'unit_id' => $encounter->unit_id,
        'disposition' => $encounter->disposition,
        'admitted_at' => $encounter->admitted_at,
        'completed_at' => $encounter->completed_at,
        'created_source' => $encounter->created_source,
    ];

    expect($encounter->arrived_at)->not->toBeNull()
        ->and($encounter->started_at)->toBeNull();

    $encounter->start();

    expect($encounter->status)->toBe(EncounterStatus::Active)
        ->and($encounter->started_at)->not->toBeNull();

    // value equality — start() must not change any of these (a re-hydrated cast
    // instance is not object-identical, so compare by value)
    foreach ($before as $key => $value) {
        expect($encounter->{$key})->toEqual($value);
    }
});

test('start() records exactly one PHI-free EncounterStarted event (final readonly)', function () {
    $tenantId = (string) Str::uuid7();
    $facilityId = (string) Str::uuid7();
    $patientId = (string) Str::uuid7();
    $encounterId = (string) Str::uuid7();

    $encounter = openEncounter([
        'id' => $encounterId, 'tenantId' => $tenantId, 'facilityId' => $facilityId,
        'patientId' => $patientId, 'status' => EncounterStatus::Arrived,
    ]);
    $encounter->releaseEvents();

    $encounter->start();
    $events = $encounter->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(EncounterStarted::class);

    /** @var EncounterStarted $event */
    $event = $events[0];
    expect($event->tenantId)->toBe($tenantId)
        ->and($event->facilityId)->toBe($facilityId)
        ->and($event->encounterId)->toBe($encounterId)
        ->and($event->patientId)->toBe($patientId)
        ->and($event->eventId)->not->toBeEmpty()
        ->and($event->occurredAt)->not->toBeEmpty();

    // exact 8-key PHI-free payload
    expect(array_keys(get_object_vars($event)))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'encounterId', 'patientId', 'occurredAt', 'correlationId', 'causationId',
    ]);

    $reflection = new ReflectionClass(EncounterStarted::class);
    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});

// ── E.4 — admit() ───────────────────────────────────────────────────

test('admit() converts OUTPATIENT|EMERGENCY -> INPATIENT, sets admitted_at + disposition ADMITTED, keeps status ACTIVE, touches nothing else', function (EncounterType $sourceType) {
    $encounter = openEncounter([
        'status' => EncounterStatus::Active,
        'type' => $sourceType,
        'departmentId' => (string) Str::uuid7(),
        'unitId' => (string) Str::uuid7(),
    ]);
    // a real ACTIVE encounter has arrival + start already recorded
    $encounter->arrived_at = now()->subMinutes(30);
    $encounter->started_at = now()->subMinutes(15);
    $encounter->releaseEvents(); // drop EncounterCreated

    $before = [
        'arrived_at' => $encounter->arrived_at,
        'started_at' => $encounter->started_at,
        'patient_id' => $encounter->patient_id,
        'tenant_id' => $encounter->tenant_id,
        'facility_id' => $encounter->facility_id,
        'encounter_number' => $encounter->encounter_number,
        'department_id' => $encounter->department_id,
        'unit_id' => $encounter->unit_id,
        'completed_at' => $encounter->completed_at,
        'created_source' => $encounter->created_source,
    ];

    expect($encounter->admitted_at)->toBeNull();

    $encounter->admit();

    expect($encounter->status)->toBe(EncounterStatus::Active)
        ->and($encounter->encounter_type)->toBe(EncounterType::Inpatient)
        ->and($encounter->admitted_at)->not->toBeNull()
        ->and($encounter->disposition)->toBe(EncounterDisposition::Admitted);

    foreach ($before as $key => $value) {
        expect($encounter->{$key})->toEqual($value);
    }
})->with([
    'OUTPATIENT' => [EncounterType::Outpatient],
    'EMERGENCY' => [EncounterType::Emergency],
]);

test('admit() records exactly one PHI-free EncounterAdmitted event with the exact 8-field payload (final readonly)', function () {
    $tenantId = (string) Str::uuid7();
    $facilityId = (string) Str::uuid7();
    $patientId = (string) Str::uuid7();
    $encounterId = (string) Str::uuid7();

    $encounter = openEncounter([
        'id' => $encounterId, 'tenantId' => $tenantId, 'facilityId' => $facilityId,
        'patientId' => $patientId, 'status' => EncounterStatus::Active, 'type' => EncounterType::Emergency,
    ]);
    $encounter->releaseEvents();

    $encounter->admit();
    $events = $encounter->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(EncounterAdmitted::class);

    /** @var EncounterAdmitted $event */
    $event = $events[0];
    expect($event->tenantId)->toBe($tenantId)
        ->and($event->facilityId)->toBe($facilityId)
        ->and($event->encounterId)->toBe($encounterId)
        ->and($event->patientId)->toBe($patientId)
        ->and($event->eventId)->not->toBeEmpty()
        ->and($event->occurredAt)->not->toBeEmpty();

    expect(array_keys(get_object_vars($event)))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'encounterId', 'patientId', 'occurredAt', 'correlationId', 'causationId',
    ]);

    $reflection = new ReflectionClass(EncounterAdmitted::class);
    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});

// ── E.5 — changeDisposition() ───────────────────────────────────────

test('changeDisposition() sets disposition (null -> value) and touches nothing else, status stays ACTIVE', function (EncounterDisposition $disposition) {
    $encounter = openEncounter([
        'status' => EncounterStatus::Active,
        'type' => EncounterType::Emergency,
        'departmentId' => (string) Str::uuid7(),
        'unitId' => (string) Str::uuid7(),
    ]);
    $encounter->arrived_at = now()->subMinutes(30);
    $encounter->started_at = now()->subMinutes(15);
    $encounter->releaseEvents(); // drop EncounterCreated

    $before = [
        'status' => $encounter->status,
        'encounter_type' => $encounter->encounter_type,
        'arrived_at' => $encounter->arrived_at,
        'started_at' => $encounter->started_at,
        'admitted_at' => $encounter->admitted_at,
        'completed_at' => $encounter->completed_at,
        'patient_id' => $encounter->patient_id,
        'tenant_id' => $encounter->tenant_id,
        'facility_id' => $encounter->facility_id,
        'encounter_number' => $encounter->encounter_number,
        'department_id' => $encounter->department_id,
        'unit_id' => $encounter->unit_id,
        'created_source' => $encounter->created_source,
    ];

    expect($encounter->disposition)->toBeNull();

    $encounter->changeDisposition($disposition);

    expect($encounter->disposition)->toBe($disposition)
        ->and($encounter->status)->toBe(EncounterStatus::Active);

    foreach ($before as $key => $value) {
        expect($encounter->{$key})->toEqual($value);
    }
})->with([
    'DISCHARGED' => [EncounterDisposition::Discharged],
    'REFERRED' => [EncounterDisposition::Referred],
    'LEFT' => [EncounterDisposition::Left],
    'FOLLOW_UP' => [EncounterDisposition::FollowUp],
    'DECEASED' => [EncounterDisposition::Deceased],
    'OTHER' => [EncounterDisposition::Other],
]);

test('changeDisposition() revises an existing disposition to a different value', function () {
    $encounter = openEncounter(['status' => EncounterStatus::Active]);
    $encounter->releaseEvents();

    $encounter->changeDisposition(EncounterDisposition::Left);
    $encounter->releaseEvents();
    $encounter->changeDisposition(EncounterDisposition::Referred);

    expect($encounter->disposition)->toBe(EncounterDisposition::Referred)
        ->and($encounter->status)->toBe(EncounterStatus::Active);
});

test('changeDisposition() rejects the same-value no-op with IllegalEncounterTransition — no event', function () {
    $encounter = openEncounter(['status' => EncounterStatus::Active]);
    $encounter->releaseEvents();

    $encounter->changeDisposition(EncounterDisposition::Discharged);
    $encounter->releaseEvents();

    expect(fn () => $encounter->changeDisposition(EncounterDisposition::Discharged))
        ->toThrow(IllegalEncounterTransition::class);

    expect($encounter->releaseEvents())->toHaveCount(0)
        ->and($encounter->disposition)->toBe(EncounterDisposition::Discharged);
});

test('changeDisposition() records exactly one PHI-free EncounterDispositionChanged event with the exact 9-field payload (final readonly)', function () {
    $tenantId = (string) Str::uuid7();
    $facilityId = (string) Str::uuid7();
    $patientId = (string) Str::uuid7();
    $encounterId = (string) Str::uuid7();

    $encounter = openEncounter([
        'id' => $encounterId, 'tenantId' => $tenantId, 'facilityId' => $facilityId,
        'patientId' => $patientId, 'status' => EncounterStatus::Active,
    ]);
    $encounter->releaseEvents();

    $encounter->changeDisposition(EncounterDisposition::Referred);
    $events = $encounter->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(EncounterDispositionChanged::class);

    /** @var EncounterDispositionChanged $event */
    $event = $events[0];
    expect($event->tenantId)->toBe($tenantId)
        ->and($event->facilityId)->toBe($facilityId)
        ->and($event->encounterId)->toBe($encounterId)
        ->and($event->patientId)->toBe($patientId)
        ->and($event->disposition)->toBe('REFERRED')
        ->and($event->eventId)->not->toBeEmpty()
        ->and($event->occurredAt)->not->toBeEmpty();

    // exact 9-key payload — standard envelope + the new coded disposition, no previousDisposition
    expect(array_keys(get_object_vars($event)))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'encounterId', 'patientId',
        'disposition', 'occurredAt', 'correlationId', 'causationId',
    ]);

    $reflection = new ReflectionClass(EncounterDispositionChanged::class);
    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});

// ── E.6 — assignLocation() ─────────────────────────────────────────

test('assignLocation() sets department only / unit only / both, and touches nothing else, status unchanged', function (bool $withDept, bool $withUnit) {
    $departmentId = (string) Str::uuid7();
    $unitId = (string) Str::uuid7();
    $dept = $withDept ? $departmentId : null;
    $unit = $withUnit ? $unitId : null;

    $encounter = openEncounter(['status' => EncounterStatus::Active, 'type' => EncounterType::Emergency]);
    $encounter->arrived_at = now()->subMinutes(30);
    $encounter->started_at = now()->subMinutes(15);
    $encounter->releaseEvents();

    $before = [
        'status' => $encounter->status,
        'encounter_type' => $encounter->encounter_type,
        'disposition' => $encounter->disposition,
        'arrived_at' => $encounter->arrived_at,
        'started_at' => $encounter->started_at,
        'admitted_at' => $encounter->admitted_at,
        'completed_at' => $encounter->completed_at,
        'patient_id' => $encounter->patient_id,
        'tenant_id' => $encounter->tenant_id,
        'facility_id' => $encounter->facility_id,
        'encounter_number' => $encounter->encounter_number,
    ];

    $encounter->assignLocation($dept, $unit);

    expect($encounter->department_id)->toBe($dept)
        ->and($encounter->unit_id)->toBe($unit);

    foreach ($before as $key => $value) {
        expect($encounter->{$key})->toEqual($value);
    }
})->with([
    'department only' => [true, false],
    'unit only' => [false, true],
    'both' => [true, true],
]);

test('assignLocation() patch semantics — a null argument leaves that field unchanged', function () {
    $dept = (string) Str::uuid7();
    $unit1 = (string) Str::uuid7();
    $unit2 = (string) Str::uuid7();

    $encounter = openEncounter(['status' => EncounterStatus::Active]);
    $encounter->releaseEvents();

    $encounter->assignLocation($dept, $unit1);
    $encounter->releaseEvents();

    // patch just the unit — department must be preserved
    $encounter->assignLocation(null, $unit2);

    expect($encounter->department_id)->toBe($dept)
        ->and($encounter->unit_id)->toBe($unit2);
});

test('assignLocation() changes an already-assigned location (the move case)', function () {
    $encounter = openEncounter(['status' => EncounterStatus::Arrived]);
    $encounter->releaseEvents();

    $encounter->assignLocation((string) Str::uuid7(), (string) Str::uuid7());
    $encounter->releaseEvents();

    $newDept = (string) Str::uuid7();
    $encounter->assignLocation($newDept, null);

    expect($encounter->department_id)->toBe($newDept);
});

test('assignLocation() rejects the resolved no-op with IllegalEncounterTransition — no event', function () {
    $dept = (string) Str::uuid7();
    $unit = (string) Str::uuid7();

    $encounter = openEncounter(['status' => EncounterStatus::Active]);
    $encounter->releaseEvents();
    $encounter->assignLocation($dept, $unit);
    $encounter->releaseEvents();

    // same pair again
    expect(fn () => $encounter->assignLocation($dept, $unit))
        ->toThrow(IllegalEncounterTransition::class);
    // a null patch that resolves to the same pair
    expect(fn () => $encounter->assignLocation($dept, null))
        ->toThrow(IllegalEncounterTransition::class);

    expect($encounter->releaseEvents())->toHaveCount(0)
        ->and($encounter->department_id)->toBe($dept)
        ->and($encounter->unit_id)->toBe($unit);
});

test('assignLocation() records exactly one PHI-free EncounterLocationChanged event with the exact 10-field payload (final readonly)', function () {
    $tenantId = (string) Str::uuid7();
    $facilityId = (string) Str::uuid7();
    $patientId = (string) Str::uuid7();
    $encounterId = (string) Str::uuid7();
    $departmentId = (string) Str::uuid7();

    $encounter = openEncounter([
        'id' => $encounterId, 'tenantId' => $tenantId, 'facilityId' => $facilityId,
        'patientId' => $patientId, 'status' => EncounterStatus::Active,
    ]);
    $encounter->releaseEvents();

    $encounter->assignLocation($departmentId, null);
    $events = $encounter->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(EncounterLocationChanged::class);

    /** @var EncounterLocationChanged $event */
    $event = $events[0];
    expect($event->tenantId)->toBe($tenantId)
        ->and($event->facilityId)->toBe($facilityId)
        ->and($event->encounterId)->toBe($encounterId)
        ->and($event->patientId)->toBe($patientId)
        ->and($event->departmentId)->toBe($departmentId)
        ->and($event->unitId)->toBeNull()
        ->and($event->eventId)->not->toBeEmpty()
        ->and($event->occurredAt)->not->toBeEmpty();

    // exact 10-key payload — standard envelope + current location, no previous location
    expect(array_keys(get_object_vars($event)))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'encounterId', 'patientId',
        'departmentId', 'unitId', 'occurredAt', 'correlationId', 'causationId',
    ]);

    $reflection = new ReflectionClass(EncounterLocationChanged::class);
    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});

// ── E.7 — complete() ──────────────────────────────────────────────

test('complete() moves ACTIVE -> COMPLETED, sets completed_at, preserves the disposition and everything else', function (EncounterDisposition $disposition) {
    $encounter = openEncounter(['status' => EncounterStatus::Active, 'type' => EncounterType::Emergency]);
    $encounter->arrived_at = now()->subMinutes(40);
    $encounter->started_at = now()->subMinutes(25);
    $encounter->admitted_at = now()->subMinutes(10);
    $encounter->department_id = (string) Str::uuid7();
    $encounter->unit_id = (string) Str::uuid7();
    $encounter->disposition = $disposition;
    $encounter->releaseEvents(); // drop EncounterCreated

    $before = [
        'encounter_type' => $encounter->encounter_type,
        'disposition' => $encounter->disposition,
        'arrived_at' => $encounter->arrived_at,
        'started_at' => $encounter->started_at,
        'admitted_at' => $encounter->admitted_at,
        'department_id' => $encounter->department_id,
        'unit_id' => $encounter->unit_id,
        'patient_id' => $encounter->patient_id,
        'tenant_id' => $encounter->tenant_id,
        'facility_id' => $encounter->facility_id,
        'encounter_number' => $encounter->encounter_number,
        'created_source' => $encounter->created_source,
    ];

    expect($encounter->completed_at)->toBeNull();

    $encounter->complete();

    expect($encounter->status)->toBe(EncounterStatus::Completed)
        ->and($encounter->completed_at)->not->toBeNull();

    foreach ($before as $key => $value) {
        expect($encounter->{$key})->toEqual($value);
    }
})->with([
    'DISCHARGED' => [EncounterDisposition::Discharged],
    'REFERRED' => [EncounterDisposition::Referred],
    'LEFT' => [EncounterDisposition::Left],
    'FOLLOW_UP' => [EncounterDisposition::FollowUp],
    'DECEASED' => [EncounterDisposition::Deceased],
    'OTHER' => [EncounterDisposition::Other],
    'ADMITTED (inpatient, no discharge revision)' => [EncounterDisposition::Admitted],
]);

test('complete() sets completed_at to a server-generated CarbonImmutable (no caller timestamp)', function () {
    $encounter = openEncounter(['status' => EncounterStatus::Active]);
    $encounter->disposition = EncounterDisposition::Discharged;
    $encounter->releaseEvents();

    $encounter->complete();

    expect($encounter->completed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and(abs($encounter->completed_at->getTimestamp() - now()->getTimestamp()))->toBeLessThan(5);
});

test('complete() records exactly one PHI-free EncounterCompleted event with the exact 9-field payload (final readonly)', function () {
    $tenantId = (string) Str::uuid7();
    $facilityId = (string) Str::uuid7();
    $patientId = (string) Str::uuid7();
    $encounterId = (string) Str::uuid7();

    $encounter = openEncounter([
        'id' => $encounterId, 'tenantId' => $tenantId, 'facilityId' => $facilityId,
        'patientId' => $patientId, 'status' => EncounterStatus::Active,
    ]);
    $encounter->disposition = EncounterDisposition::Referred;
    $encounter->releaseEvents();

    $encounter->complete();
    $events = $encounter->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(EncounterCompleted::class);

    /** @var EncounterCompleted $event */
    $event = $events[0];
    expect($event->tenantId)->toBe($tenantId)
        ->and($event->facilityId)->toBe($facilityId)
        ->and($event->encounterId)->toBe($encounterId)
        ->and($event->patientId)->toBe($patientId)
        ->and($event->disposition)->toBe('REFERRED')
        ->and($event->eventId)->not->toBeEmpty()
        ->and($event->occurredAt)->not->toBeEmpty();

    // exact 9-key payload — standard envelope + the coded disposition; no completedAt / encounterType / previousStatus
    expect(array_keys(get_object_vars($event)))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'encounterId', 'patientId',
        'disposition', 'occurredAt', 'correlationId', 'causationId',
    ]);

    $reflection = new ReflectionClass(EncounterCompleted::class);
    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});

// ── E.11 — transfer() ─────────────────────────────────────────────

test('transfer() moves ACTIVE -> TRANSFERRED, sets disposition TRANSFERRED, preserves everything else, completed_at stays null', function (EncounterType $type) {
    $encounter = openEncounter(['status' => EncounterStatus::Active, 'type' => $type]);
    $encounter->arrived_at = now()->subMinutes(40);
    $encounter->started_at = now()->subMinutes(25);
    $encounter->department_id = (string) Str::uuid7();
    $encounter->unit_id = (string) Str::uuid7();
    $encounter->releaseEvents(); // drop EncounterCreated

    $before = [
        'encounter_type' => $encounter->encounter_type,
        'arrived_at' => $encounter->arrived_at,
        'started_at' => $encounter->started_at,
        'admitted_at' => $encounter->admitted_at,
        'department_id' => $encounter->department_id,
        'unit_id' => $encounter->unit_id,
        'patient_id' => $encounter->patient_id,
        'tenant_id' => $encounter->tenant_id,
        'facility_id' => $encounter->facility_id,
        'encounter_number' => $encounter->encounter_number,
        'created_by_user_id' => $encounter->created_by_user_id,
        'created_source' => $encounter->created_source,
    ];

    expect($encounter->completed_at)->toBeNull();

    $encounter->transfer();

    expect($encounter->status)->toBe(EncounterStatus::Transferred)
        ->and($encounter->disposition)->toBe(EncounterDisposition::Transferred)
        ->and($encounter->completed_at)->toBeNull();

    foreach ($before as $key => $value) {
        expect($encounter->{$key})->toEqual($value);
    }
})->with([
    'OUTPATIENT' => [EncounterType::Outpatient],
    'EMERGENCY' => [EncounterType::Emergency],
    'INPATIENT' => [EncounterType::Inpatient],
    'DIAGNOSTIC' => [EncounterType::Diagnostic],
]);

test('transfer() overwrites an existing disposition with TRANSFERRED', function (EncounterDisposition $prior) {
    $encounter = openEncounter(['status' => EncounterStatus::Active]);
    $encounter->disposition = $prior;
    $encounter->releaseEvents();

    $encounter->transfer();

    expect($encounter->disposition)->toBe(EncounterDisposition::Transferred)
        ->and($encounter->status)->toBe(EncounterStatus::Transferred);
})->with([
    'ADMITTED' => [EncounterDisposition::Admitted],
    'REFERRED' => [EncounterDisposition::Referred],
    'DISCHARGED' => [EncounterDisposition::Discharged],
]);

test('transfer() records exactly one PHI-free EncounterTransferred event with the exact 8-field payload (final readonly)', function () {
    $tenantId = (string) Str::uuid7();
    $facilityId = (string) Str::uuid7();
    $patientId = (string) Str::uuid7();
    $encounterId = (string) Str::uuid7();

    $encounter = openEncounter([
        'id' => $encounterId, 'tenantId' => $tenantId, 'facilityId' => $facilityId,
        'patientId' => $patientId, 'status' => EncounterStatus::Active,
    ]);
    $encounter->releaseEvents();

    $encounter->transfer();
    $events = $encounter->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(EncounterTransferred::class);

    /** @var EncounterTransferred $event */
    $event = $events[0];
    expect($event->tenantId)->toBe($tenantId)
        ->and($event->facilityId)->toBe($facilityId)
        ->and($event->encounterId)->toBe($encounterId)
        ->and($event->patientId)->toBe($patientId)
        ->and($event->eventId)->not->toBeEmpty()
        ->and($event->occurredAt)->not->toBeEmpty();

    // exact 8-key PHI-free payload — standard envelope only; no status / disposition / destination
    expect(array_keys(get_object_vars($event)))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'encounterId', 'patientId', 'occurredAt', 'correlationId', 'causationId',
    ]);

    $reflection = new ReflectionClass(EncounterTransferred::class);
    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});
