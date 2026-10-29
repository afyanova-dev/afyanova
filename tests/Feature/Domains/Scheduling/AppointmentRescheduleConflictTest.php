<?php

declare(strict_types=1);

use App\Domains\Scheduling\Application\Command\BookAppointment;
use App\Domains\Scheduling\Application\Command\RescheduleAppointment;
use App\Domains\Scheduling\Domain\Exception\AppointmentConflictException;
use App\Domains\Scheduling\Domain\Model\Appointment;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../Support/scheduling.php';
require_once __DIR__.'/../../../Support/hr.php';

function reschedFixture(): array
{
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Specialist Consultation', 30, facilityId: $facilityId);

    return compact('tenantId', 'facilityId', 'resourceId', 'typeId');
}

function reschedBook(string $tenantId, string $facilityId, string $resourceId, string $typeId, string $start, string $end): string
{
    $appointmentId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($appointmentId, $resourceId, $typeId, $start, $end): void {
        app(CommandBus::class)->dispatch(new BookAppointment(
            patientId: (string) Str::uuid(),
            resourceId: $resourceId,
            appointmentTypeId: $typeId,
            scheduledStart: $start,
            scheduledEnd: $end,
            appointmentId: $appointmentId,
        ));
    });

    return $appointmentId;
}

function reschedMove(string $tenantId, string $facilityId, string $appointmentId, string $start, string $end): void
{
    TenantContextScope::run($tenantId, $facilityId, function () use ($appointmentId, $start, $end): void {
        app(CommandBus::class)->dispatch(new RescheduleAppointment(
            appointmentId: $appointmentId,
            newScheduledStart: $start,
            newScheduledEnd: $end,
        ));
    });
}

it('moves an appointment to a free interval', function (): void {
    ['tenantId' => $tenantId, 'facilityId' => $facilityId, 'resourceId' => $resourceId, 'typeId' => $typeId] = reschedFixture();
    $day = CarbonImmutable::now()->addDay()->format('Y-m-d');

    $id = reschedBook($tenantId, $facilityId, $resourceId, $typeId, "{$day}T09:00:00", "{$day}T09:30:00");
    reschedMove($tenantId, $facilityId, $id, "{$day}T11:00:00", "{$day}T11:30:00");

    $start = TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) Appointment::query()->whereKey($id)->value('scheduled_start'));
    expect($start)->toContain('11:00');
});

it('rejects a move onto another appointment interval', function (): void {
    ['tenantId' => $tenantId, 'facilityId' => $facilityId, 'resourceId' => $resourceId, 'typeId' => $typeId] = reschedFixture();
    $day = CarbonImmutable::now()->addDay()->format('Y-m-d');

    reschedBook($tenantId, $facilityId, $resourceId, $typeId, "{$day}T09:00:00", "{$day}T09:30:00");
    $moving = reschedBook($tenantId, $facilityId, $resourceId, $typeId, "{$day}T11:00:00", "{$day}T11:30:00");

    expect(fn () => reschedMove($tenantId, $facilityId, $moving, "{$day}T09:15:00", "{$day}T09:45:00"))
        ->toThrow(AppointmentConflictException::class);
});

it('allows shifting within its own current interval (self excluded)', function (): void {
    ['tenantId' => $tenantId, 'facilityId' => $facilityId, 'resourceId' => $resourceId, 'typeId' => $typeId] = reschedFixture();
    $day = CarbonImmutable::now()->addDay()->format('Y-m-d');

    $id = reschedBook($tenantId, $facilityId, $resourceId, $typeId, "{$day}T09:00:00", "{$day}T10:00:00");
    reschedMove($tenantId, $facilityId, $id, "{$day}T09:30:00", "{$day}T10:30:00");

    $start = TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) Appointment::query()->whereKey($id)->value('scheduled_start'));
    expect($start)->toContain('09:30');
});

it('allows a boundary-adjacent move', function (): void {
    ['tenantId' => $tenantId, 'facilityId' => $facilityId, 'resourceId' => $resourceId, 'typeId' => $typeId] = reschedFixture();
    $day = CarbonImmutable::now()->addDay()->format('Y-m-d');

    reschedBook($tenantId, $facilityId, $resourceId, $typeId, "{$day}T09:00:00", "{$day}T09:30:00");
    $moving = reschedBook($tenantId, $facilityId, $resourceId, $typeId, "{$day}T11:00:00", "{$day}T11:30:00");
    reschedMove($tenantId, $facilityId, $moving, "{$day}T09:30:00", "{$day}T10:00:00");

    $count = TenantContextScope::run($tenantId, $facilityId, fn (): int => Appointment::query()->count());
    expect($count)->toBe(2);
});
