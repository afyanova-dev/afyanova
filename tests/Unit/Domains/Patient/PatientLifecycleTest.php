<?php

declare(strict_types=1);

use App\Domains\Patient\Contracts\Event\PatientDeactivated;
use App\Domains\Patient\Contracts\Event\PatientMarkedDeceased;
use App\Domains\Patient\Contracts\Event\PatientReactivated;
use App\Domains\Patient\Domain\Enum\DobPrecision;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Domains\Patient\Domain\Exception\IllegalPatientStatusTransition;
use App\Domains\Patient\Domain\Model\Patient;
use Illuminate\Support\Str;

/*
 | 10-patient-domain.md §44-45, §130, §132 / ADR-116 — the lifecycle
 | transition guards on the aggregate itself, independent of persistence.
 |
 | ADR-116 state machine:
 |   ACTIVE   <-> INACTIVE
 |   {ACTIVE, INACTIVE} -> DECEASED   (terminal in PHASE 5)
 |   MERGED is only reachable via the merge process (not this increment)
 */

/**
 * A freshly registered (ACTIVE) patient, events already drained.
 */
function makePatient(): Patient
{
    $patient = Patient::register(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        patientNumber: 'PAT-00000001',
        facilityId: (string) Str::uuid7(),
        givenName: 'Asha',
        familyName: 'Mkwawa',
        middleName: null,
        displayName: 'Asha Mkwawa',
        sex: Sex::Female,
        dateOfBirth: '1990-05-01',
        dobPrecision: DobPrecision::Known,
        primaryPhone: null,
        email: null,
        provisional: false,
        source: 'RECEPTION',
        registeredByUserId: (string) Str::uuid7(),
    );
    $patient->releaseEvents();

    return $patient;
}

test('markInactive() moves ACTIVE -> INACTIVE and records PatientDeactivated', function () {
    $patient = makePatient();

    $patient->markInactive();

    expect($patient->status)->toBe(PatientStatus::Inactive);

    $events = $patient->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PatientDeactivated::class);

    /** @var PatientDeactivated $event */
    $event = $events[0];
    expect($event->patientId)->toBe((string) $patient->id)
        ->and($event->tenantId)->toBe((string) $patient->tenant_id)
        ->and($event->eventId)->toBeUuid();
});

test('reactivate() moves INACTIVE -> ACTIVE and records PatientReactivated', function () {
    $patient = makePatient();
    $patient->markInactive();
    $patient->releaseEvents();

    $patient->reactivate();

    expect($patient->status)->toBe(PatientStatus::Active);

    $events = $patient->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PatientReactivated::class);
});

test('markDeceased() moves ACTIVE -> DECEASED and records PatientMarkedDeceased', function () {
    $patient = makePatient();

    $patient->markDeceased();

    expect($patient->status)->toBe(PatientStatus::Deceased);
    expect($patient->releaseEvents()[0])->toBeInstanceOf(PatientMarkedDeceased::class);
});

test('markDeceased() also permits INACTIVE -> DECEASED (ADR-116)', function () {
    $patient = makePatient();
    $patient->markInactive();
    $patient->releaseEvents();

    $patient->markDeceased();

    expect($patient->status)->toBe(PatientStatus::Deceased);
});

test('markInactive() on an already-INACTIVE patient is rejected (ADR-116)', function () {
    $patient = makePatient();
    $patient->markInactive();
    $patient->releaseEvents();

    expect(fn () => $patient->markInactive())
        ->toThrow(IllegalPatientStatusTransition::class);
});

test('reactivate() on an ACTIVE patient is rejected (ADR-116)', function () {
    $patient = makePatient();

    expect(fn () => $patient->reactivate())
        ->toThrow(IllegalPatientStatusTransition::class);
});

test('DECEASED is terminal — no transition leaves it (ADR-116)', function () {
    $patient = makePatient();
    $patient->markDeceased();
    $patient->releaseEvents();

    expect(fn () => $patient->markInactive())->toThrow(IllegalPatientStatusTransition::class);
    expect(fn () => $patient->reactivate())->toThrow(IllegalPatientStatusTransition::class);
    expect(fn () => $patient->markDeceased())->toThrow(IllegalPatientStatusTransition::class);

    expect($patient->status)->toBe(PatientStatus::Deceased);
});

test('IllegalPatientStatusTransition maps to HTTP 409 with a machine code (IC §15)', function () {
    $exception = IllegalPatientStatusTransition::from(PatientStatus::Deceased, PatientStatus::Active);

    expect($exception->httpStatus())->toBe(409)
        ->and($exception->errorCode())->toBe('illegal_patient_status_transition');
});

test('lifecycle events carry no PHI (IC §7.1.1)', function () {
    $patient = Patient::register(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        patientNumber: 'PAT-00000009',
        facilityId: (string) Str::uuid7(),
        givenName: 'Asha',
        familyName: 'Mkwawa',
        middleName: null,
        displayName: 'Asha Mkwawa',
        sex: Sex::Female,
        dateOfBirth: '1990-05-01',
        dobPrecision: DobPrecision::Known,
        primaryPhone: '+255700000000',
        email: 'asha@example.test',
        provisional: false,
        source: 'RECEPTION',
        registeredByUserId: (string) Str::uuid7(),
    );
    $patient->releaseEvents();

    $patient->markDeceased();
    $payload = (array) $patient->releaseEvents()[0];

    foreach (['Asha', 'Mkwawa', '1990-05-01', '+255700000000', 'asha@example.test'] as $phi) {
        expect($payload)->not->toContain($phi);
    }
});
