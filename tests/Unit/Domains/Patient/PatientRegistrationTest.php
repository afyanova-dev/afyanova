<?php

declare(strict_types=1);

use App\Domains\Patient\Contracts\Event\PatientRegisteredAtFacility;
use App\Domains\Patient\Domain\Model\PatientRegistration;
use Illuminate\Support\Str;

test('register() builds an ACTIVE registration and records PatientRegisteredAtFacility', function () {
    $tenantId = (string) Str::uuid7();
    $facilityId = (string) Str::uuid7();
    $patientId = (string) Str::uuid7();

    $registration = PatientRegistration::register($tenantId, $facilityId, $patientId);

    expect((string) $registration->tenant_id)->toBe($tenantId)
        ->and((string) $registration->facility_id)->toBe($facilityId)
        ->and((string) $registration->patient_id)->toBe($patientId)
        ->and($registration->status)->toBe('ACTIVE')
        ->and((int) $registration->version)->toBe(0);

    $events = $registration->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PatientRegisteredAtFacility::class);

    /** @var PatientRegisteredAtFacility $event */
    $event = $events[0];
    expect($event->tenantId)->toBe($tenantId)
        ->and($event->facilityId)->toBe($facilityId)
        ->and($event->patientId)->toBe($patientId);
});
