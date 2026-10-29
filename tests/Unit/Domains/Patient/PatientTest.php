<?php

declare(strict_types=1);

use App\Domains\Patient\Contracts\Event\PatientRegistered;
use App\Domains\Patient\Domain\Enum\DobPrecision;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Domains\Patient\Domain\Model\Patient;
use Illuminate\Support\Str;

/*
 | 10-patient-domain.md §15 / ADR-114 / ADR-116 / ADR-119 — the aggregate's
 | own invariants, independent of persistence.
 */

test('register() builds an ACTIVE, non-provisional patient and records PatientRegistered', function () {
    $id = (string) Str::uuid7();
    $tenantId = (string) Str::uuid7();
    $facilityId = (string) Str::uuid7();
    $actorId = (string) Str::uuid7();

    $patient = Patient::register(
        id: $id,
        tenantId: $tenantId,
        patientNumber: 'PAT-00000001',
        facilityId: $facilityId,
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
        registeredByUserId: $actorId,
    );

    expect((string) $patient->id)->toBe($id)
        ->and((string) $patient->tenant_id)->toBe($tenantId)
        ->and($patient->patient_number)->toBe('PAT-00000001')
        ->and($patient->status)->toBe(PatientStatus::Active)
        ->and($patient->sex)->toBe(Sex::Female)
        ->and($patient->dob_precision)->toBe(DobPrecision::Known)
        ->and($patient->is_provisional)->toBeFalse()
        ->and((int) $patient->version)->toBe(0);

    $events = $patient->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PatientRegistered::class);

    /** @var PatientRegistered $event */
    $event = $events[0];
    expect($event->patientId)->toBe($id)
        ->and($event->tenantId)->toBe($tenantId)
        ->and($event->facilityId)->toBe($facilityId)
        ->and($event->patientNumber)->toBe('PAT-00000001')
        ->and($event->isProvisional)->toBeFalse()
        ->and($event->eventId)->toBeUuid();

    // the buffer is drained by release — a second call returns nothing new
    expect($patient->releaseEvents())->toBe([]);
});

test('register() with provisional=true sets is_provisional on the aggregate and the event (ADR-119)', function () {
    $patient = Patient::register(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        patientNumber: 'PAT-00000002',
        facilityId: (string) Str::uuid7(),
        givenName: 'Unknown',
        familyName: 'Patient',
        middleName: null,
        displayName: 'Unknown Patient',
        sex: Sex::Unknown,
        dateOfBirth: null,
        dobPrecision: DobPrecision::Unknown,
        primaryPhone: null,
        email: null,
        provisional: true,
        source: 'EMERGENCY',
        registeredByUserId: (string) Str::uuid7(),
    );

    expect($patient->is_provisional)->toBeTrue()
        ->and($patient->date_of_birth)->toBeNull()
        ->and($patient->dob_precision)->toBe(DobPrecision::Unknown);

    /** @var PatientRegistered $event */
    $event = $patient->releaseEvents()[0];
    expect($event->isProvisional)->toBeTrue();
});

test('PatientRegistered carries no PHI (IC §7.1.1) — identifiers, a flag, and coded/temporal fields only', function () {
    $patient = Patient::register(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        patientNumber: 'PAT-00000003',
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

    $event = $patient->releaseEvents()[0];
    $payload = (array) $event;

    // none of the PHI-bearing input values leak into the event payload
    foreach (['Asha', 'Mkwawa', '1990-05-01', '+255700000000', 'asha@example.test'] as $phi) {
        expect($payload)->not->toContain($phi);
    }
});
