<?php

declare(strict_types=1);

use App\Domains\Scheduling\Application\Command\BookAppointment;
use App\Domains\Scheduling\Application\Command\CancelAppointment;
use App\Domains\Scheduling\Domain\Exception\AppointmentConflictException;
use App\Domains\Scheduling\Domain\Model\Appointment;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../Support/scheduling.php';
require_once __DIR__.'/../../../Support/hr.php';

beforeEach(function (): void {
    $this->bus = app(CommandBus::class);
});

function conflictFixture(): array
{
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Specialist Consultation', 30, facilityId: $facilityId);

    return compact('tenantId', 'facilityId', 'resourceId', 'typeId');
}

function bookAt(string $tenantId, string $facilityId, string $resourceId, string $typeId, string $patientId, string $start, string $end, ?string $slotId = null, ?string $appointmentId = null): void
{
    TenantContextScope::run($tenantId, $facilityId, function () use ($patientId, $resourceId, $typeId, $start, $end, $slotId, $appointmentId): void {
        app(CommandBus::class)->dispatch(new BookAppointment(
            patientId: $patientId,
            resourceId: $resourceId,
            appointmentTypeId: $typeId,
            scheduledStart: $start,
            scheduledEnd: $end,
            slotId: $slotId,
            appointmentId: $appointmentId,
        ));
    });
}

it('rejects a slot-less booking overlapping an existing appointment', function (): void {
    ['tenantId' => $tenantId, 'facilityId' => $facilityId, 'resourceId' => $resourceId, 'typeId' => $typeId] = conflictFixture();
    $day = CarbonImmutable::now()->addDay()->format('Y-m-d');

    bookAt($tenantId, $facilityId, $resourceId, $typeId, (string) Str::uuid(), "{$day}T09:00:00", "{$day}T09:30:00");

    expect(fn () => bookAt($tenantId, $facilityId, $resourceId, $typeId, (string) Str::uuid(), "{$day}T09:15:00", "{$day}T09:45:00"))
        ->toThrow(AppointmentConflictException::class);
});

it('allows back-to-back bookings on interval boundaries', function (): void {
    ['tenantId' => $tenantId, 'facilityId' => $facilityId, 'resourceId' => $resourceId, 'typeId' => $typeId] = conflictFixture();
    $day = CarbonImmutable::now()->addDay()->format('Y-m-d');

    bookAt($tenantId, $facilityId, $resourceId, $typeId, (string) Str::uuid(), "{$day}T09:00:00", "{$day}T09:30:00");
    bookAt($tenantId, $facilityId, $resourceId, $typeId, (string) Str::uuid(), "{$day}T09:30:00", "{$day}T10:00:00");

    $count = TenantContextScope::run($tenantId, $facilityId, fn (): int => Appointment::query()->count());
    expect($count)->toBe(2);
});

it('ignores cancelled appointments when checking conflicts', function (): void {
    ['tenantId' => $tenantId, 'facilityId' => $facilityId, 'resourceId' => $resourceId, 'typeId' => $typeId] = conflictFixture();
    $day = CarbonImmutable::now()->addDay()->format('Y-m-d');
    $patientId = (string) Str::uuid();

    bookAt($tenantId, $facilityId, $resourceId, $typeId, $patientId, "{$day}T09:00:00", "{$day}T09:30:00", appointmentId: $appointmentId = (string) Str::uuid());

    TenantContextScope::run($tenantId, $facilityId, function () use ($appointmentId): void {
        app(CommandBus::class)->dispatch(new CancelAppointment(
            appointmentId: $appointmentId,
            reason: 'Patient unwell',
        ));
    });

    bookAt($tenantId, $facilityId, $resourceId, $typeId, (string) Str::uuid(), "{$day}T09:15:00", "{$day}T09:45:00");

    $count = TenantContextScope::run($tenantId, $facilityId, fn (): int => Appointment::query()->count());
    expect($count)->toBe(2);
});

it('rejects a second identical booking (simultaneous-request behaviour)', function (): void {
    ['tenantId' => $tenantId, 'facilityId' => $facilityId, 'resourceId' => $resourceId, 'typeId' => $typeId] = conflictFixture();
    $day = CarbonImmutable::now()->addDay()->format('Y-m-d');

    bookAt($tenantId, $facilityId, $resourceId, $typeId, (string) Str::uuid(), "{$day}T11:00:00", "{$day}T11:30:00");

    // Same interval twice in a row: the row lock on the resource serializes
    // the two requests inside their transactions, so the loser observes the
    // winner's committed row and throws instead of double-booking.
    expect(fn () => bookAt($tenantId, $facilityId, $resourceId, $typeId, (string) Str::uuid(), "{$day}T11:00:00", "{$day}T11:30:00"))
        ->toThrow(AppointmentConflictException::class);
    $count = TenantContextScope::run($tenantId, $facilityId, fn (): int => Appointment::query()->count());
    expect($count)->toBe(1);
});
