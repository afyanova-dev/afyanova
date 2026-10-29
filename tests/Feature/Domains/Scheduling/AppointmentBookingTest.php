<?php

declare(strict_types=1);

use App\Domains\Scheduling\Application\Command\BookAppointment;
use App\Domains\Scheduling\Application\Command\CancelAppointment;
use App\Domains\Scheduling\Application\Command\ConfirmAppointment;
use App\Domains\Scheduling\Application\Command\MarkAppointmentNoShow;
use App\Domains\Scheduling\Application\Command\RecordAppointmentArrival;
use App\Domains\Scheduling\Application\Command\RescheduleAppointment;
use App\Domains\Scheduling\Application\Query\GetAppointment;
use App\Domains\Scheduling\Application\Query\GetPatientAppointments;
use App\Domains\Scheduling\Application\Query\GetResourceAppointments;
use App\Domains\Scheduling\Contracts\Dto\AppointmentDetail;
use App\Domains\Scheduling\Contracts\Dto\AppointmentSummary;
use App\Domains\Scheduling\Domain\Exception\AppointmentNotFoundException;
use App\Domains\Scheduling\Domain\Exception\AppointmentSlotMismatchException;
use App\Domains\Scheduling\Domain\Exception\SlotNotAvailableException;
use App\Domains\Scheduling\Domain\Model\Appointment;
use App\Domains\Scheduling\Domain\Model\Slot;
use App\Domains\Scheduling\Domain\ValueObject\AppointmentStatus;
use App\Domains\Scheduling\Domain\ValueObject\SlotStatus;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/scheduling.php';
require_once __DIR__.'/../../../../tests/Support/hr.php';

beforeEach(function (): void {
    $this->bus = app(CommandBus::class);
    $this->queryBus = app(QueryBus::class);
});

it('books an appointment into an open slot and updates slot capacity', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Specialist Consultation', 30, facilityId: $facilityId);

    $slotDate = CarbonImmutable::now()->addDay()->format('Y-m-d');
    $startTime = CarbonImmutable::parse("{$slotDate} 09:00:00");
    $endTime = CarbonImmutable::parse("{$slotDate} 09:30:00");

    $slotId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $resourceId, $typeId, $slotId, $slotDate, $startTime, $endTime): void {
        $slot = Slot::create(
            tenantId: $tenantId,
            facilityId: $facilityId,
            resourceId: $resourceId,
            slotDate: $slotDate,
            startTime: $startTime,
            endTime: $endTime,
            capacity: 1,
            appointmentTypeId: $typeId,
            slotId: $slotId,
        );
        $slot->save();
    });

    $patientId = (string) Str::uuid();
    $appointmentId = (string) Str::uuid();

    TenantContextScope::run($tenantId, $facilityId, function () use ($patientId, $resourceId, $typeId, $slotId, $appointmentId, $startTime, $endTime, $facilityId): void {
        app(CommandBus::class)->dispatch(new BookAppointment(
            patientId: $patientId,
            resourceId: $resourceId,
            appointmentTypeId: $typeId,
            scheduledStart: $startTime->toIso8601String(),
            scheduledEnd: $endTime->toIso8601String(),
            slotId: $slotId,
            reason: 'Cardiac evaluation',
            facilityId: $facilityId,
            appointmentId: $appointmentId,
        ));
    });

    // Verify appointment row
    TenantContextScope::run($tenantId, $facilityId, function () use ($appointmentId, $patientId, $slotId): void {
        $appointment = Appointment::query()->find($appointmentId);
        expect($appointment)->not->toBeNull()
            ->and($appointment->status)->toBe(AppointmentStatus::BOOKED)
            ->and($appointment->patient_id)->toBe($patientId)
            ->and($appointment->slot_id)->toBe($slotId)
            ->and($appointment->appointment_number)->toMatch('/^APT-\d{8}$/');

        // Verify slot capacity and status
        $slot = Slot::query()->find($slotId);
        expect($slot)->not->toBeNull()
            ->and($slot->booked_count)->toBe(1)
            ->and($slot->status)->toBe(SlotStatus::BOOKED);
    });

    // Verify domain event in outbox
    $event = DB::table('shared_outbox_events')
        ->where('event_name', 'scheduling.AppointmentBooked')
        ->where('tenant_id', $tenantId)
        ->first();
    expect($event)->not->toBeNull();
});

it('rejects booking when slot has reached capacity', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Specialist Consultation', 30, facilityId: $facilityId);

    $slotDate = CarbonImmutable::now()->addDays(2)->format('Y-m-d');
    $startTime = CarbonImmutable::parse("{$slotDate} 10:00:00");
    $endTime = CarbonImmutable::parse("{$slotDate} 10:30:00");

    $slotId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $resourceId, $typeId, $slotId, $slotDate, $startTime, $endTime): void {
        $slot = Slot::create(
            tenantId: $tenantId,
            facilityId: $facilityId,
            resourceId: $resourceId,
            slotDate: $slotDate,
            startTime: $startTime,
            endTime: $endTime,
            capacity: 1,
            appointmentTypeId: $typeId,
            slotId: $slotId,
        );
        $slot->save();
    });

    // First booking succeeds
    bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: (string) Str::uuid(),
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $startTime->toIso8601String(),
        scheduledEnd: $endTime->toIso8601String(),
        slotId: $slotId,
    );

    // Second booking onto full slot throws SlotNotAvailableException
    expect(fn () => bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: (string) Str::uuid(),
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $startTime->toIso8601String(),
        scheduledEnd: $endTime->toIso8601String(),
        slotId: $slotId,
    ))->toThrow(SlotNotAvailableException::class);
});

it('rejects booking when slot resource mismatches appointment', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Specialist Consultation', 30, facilityId: $facilityId);

    $otherResourceId = createDoctorResource($tenantId, $facilityId, 'Jane', 'Doe');

    $slotDate = CarbonImmutable::now()->addDays(3)->format('Y-m-d');
    $startTime = CarbonImmutable::parse("{$slotDate} 11:00:00");
    $endTime = CarbonImmutable::parse("{$slotDate} 11:30:00");

    $slotId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $otherResourceId, $typeId, $slotId, $slotDate, $startTime, $endTime): void {
        $slot = Slot::create(
            tenantId: $tenantId,
            facilityId: $facilityId,
            resourceId: $otherResourceId,
            slotDate: $slotDate,
            startTime: $startTime,
            endTime: $endTime,
            capacity: 1,
            appointmentTypeId: $typeId,
            slotId: $slotId,
        );
        $slot->save();
    });

    expect(fn () => bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: (string) Str::uuid(),
        resourceId: $resourceId, // Mismatch!
        appointmentTypeId: $typeId,
        scheduledStart: $startTime->toIso8601String(),
        scheduledEnd: $endTime->toIso8601String(),
        slotId: $slotId,
    ))->toThrow(AppointmentSlotMismatchException::class);
});

it('confirms a booked appointment', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Specialist Consultation', 30, facilityId: $facilityId);

    $start = CarbonImmutable::now()->addDay();
    $end = $start->addMinutes(30);

    $appointmentId = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: (string) Str::uuid(),
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $start->toIso8601String(),
        scheduledEnd: $end->toIso8601String(),
    );

    TenantContextScope::run($tenantId, $facilityId, function () use ($appointmentId): void {
        app(CommandBus::class)->dispatch(new ConfirmAppointment($appointmentId));
    });

    TenantContextScope::run($tenantId, $facilityId, function () use ($appointmentId): void {
        $appointment = Appointment::query()->find($appointmentId);
        expect($appointment)->not->toBeNull()
            ->and($appointment->status)->toBe(AppointmentStatus::CONFIRMED);
    });

    $event = DB::table('shared_outbox_events')
        ->where('event_name', 'scheduling.AppointmentConfirmed')
        ->where('tenant_id', $tenantId)
        ->first();
    expect($event)->not->toBeNull();
});

it('records appointment arrival and emits appointment arrived', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Specialist Consultation', 30, facilityId: $facilityId);

    $start = CarbonImmutable::now()->addDay();
    $end = $start->addMinutes(30);

    $patientId = (string) Str::uuid();
    $appointmentId = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: $patientId,
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $start->toIso8601String(),
        scheduledEnd: $end->toIso8601String(),
    );

    $arrivalTime = CarbonImmutable::now();
    TenantContextScope::run($tenantId, $facilityId, function () use ($appointmentId, $arrivalTime): void {
        app(CommandBus::class)->dispatch(new RecordAppointmentArrival($appointmentId, $arrivalTime->toIso8601String()));
    });

    TenantContextScope::run($tenantId, $facilityId, function () use ($appointmentId): void {
        $appointment = Appointment::query()->find($appointmentId);
        expect($appointment)->not->toBeNull()
            ->and($appointment->status)->toBe(AppointmentStatus::ARRIVED)
            ->and($appointment->arrived_at)->not->toBeNull();
    });

    $event = DB::table('shared_outbox_events')
        ->where('event_name', 'scheduling.AppointmentArrived')
        ->where('tenant_id', $tenantId)
        ->first();
    expect($event)->not->toBeNull();

    $payload = json_decode((string) $event->payload, true);
    expect($payload['patientId'])->toBe($patientId)
        ->and($payload['appointmentId'])->toBe($appointmentId);
});

it('reschedules an appointment to a new slot and releases old slot', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Specialist Consultation', 30, facilityId: $facilityId);

    $slotDate = CarbonImmutable::now()->addDays(4)->format('Y-m-d');
    $slot1Start = CarbonImmutable::parse("{$slotDate} 09:00:00");
    $slot1End = CarbonImmutable::parse("{$slotDate} 09:30:00");
    $slot2Start = CarbonImmutable::parse("{$slotDate} 10:00:00");
    $slot2End = CarbonImmutable::parse("{$slotDate} 10:30:00");

    $slot1Id = (string) Str::uuid();
    $slot2Id = (string) Str::uuid();

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $resourceId, $typeId, $slot1Id, $slot2Id, $slotDate, $slot1Start, $slot1End, $slot2Start, $slot2End): void {
        $slot1 = Slot::create($tenantId, $facilityId, $resourceId, $slotDate, $slot1Start, $slot1End, 1, $typeId, $slot1Id);
        $slot1->save();

        $slot2 = Slot::create($tenantId, $facilityId, $resourceId, $slotDate, $slot2Start, $slot2End, 1, $typeId, $slot2Id);
        $slot2->save();
    });

    $appointmentId = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: (string) Str::uuid(),
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $slot1Start->toIso8601String(),
        scheduledEnd: $slot1End->toIso8601String(),
        slotId: $slot1Id,
    );

    // Verify slot 1 is booked
    TenantContextScope::run($tenantId, $facilityId, function () use ($slot1Id): void {
        $slot1 = Slot::query()->find($slot1Id);
        expect($slot1->status)->toBe(SlotStatus::BOOKED);
    });

    // Reschedule to slot 2
    TenantContextScope::run($tenantId, $facilityId, function () use ($appointmentId, $slot2Id, $slot2Start, $slot2End): void {
        app(CommandBus::class)->dispatch(new RescheduleAppointment(
            appointmentId: $appointmentId,
            newScheduledStart: $slot2Start->toIso8601String(),
            newScheduledEnd: $slot2End->toIso8601String(),
            newSlotId: $slot2Id,
        ));
    });

    // Verify slot 1 is restored to OPEN and slot 2 is BOOKED
    TenantContextScope::run($tenantId, $facilityId, function () use ($slot1Id, $slot2Id, $appointmentId): void {
        $slot1 = Slot::query()->find($slot1Id);
        expect($slot1->status)->toBe(SlotStatus::OPEN)
            ->and($slot1->booked_count)->toBe(0);

        $slot2 = Slot::query()->find($slot2Id);
        expect($slot2->status)->toBe(SlotStatus::BOOKED)
            ->and($slot2->booked_count)->toBe(1);

        $appointment = Appointment::query()->find($appointmentId);
        expect($appointment->slot_id)->toBe($slot2Id)
            ->and($appointment->status)->toBe(AppointmentStatus::BOOKED);
    });

    $event = DB::table('shared_outbox_events')
        ->where('event_name', 'scheduling.AppointmentRescheduled')
        ->where('tenant_id', $tenantId)
        ->first();
    expect($event)->not->toBeNull();
});

it('cancels an appointment and restores slot capacity', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Specialist Consultation', 30, facilityId: $facilityId);

    $slotDate = CarbonImmutable::now()->addDays(5)->format('Y-m-d');
    $startTime = CarbonImmutable::parse("{$slotDate} 14:00:00");
    $endTime = CarbonImmutable::parse("{$slotDate} 14:30:00");

    $slotId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $resourceId, $typeId, $slotId, $slotDate, $startTime, $endTime): void {
        $slot = Slot::create($tenantId, $facilityId, $resourceId, $slotDate, $startTime, $endTime, 1, $typeId, $slotId);
        $slot->save();
    });

    $appointmentId = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: (string) Str::uuid(),
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $startTime->toIso8601String(),
        scheduledEnd: $endTime->toIso8601String(),
        slotId: $slotId,
    );

    TenantContextScope::run($tenantId, $facilityId, function () use ($appointmentId): void {
        app(CommandBus::class)->dispatch(new CancelAppointment(
            appointmentId: $appointmentId,
            reason: 'Patient unwell',
        ));
    });

    TenantContextScope::run($tenantId, $facilityId, function () use ($appointmentId, $slotId): void {
        $appointment = Appointment::query()->find($appointmentId);
        expect($appointment->status)->toBe(AppointmentStatus::CANCELLED)
            ->and($appointment->cancellation_reason)->toBe('Patient unwell');

        $slot = Slot::query()->find($slotId);
        expect($slot->status)->toBe(SlotStatus::OPEN)
            ->and($slot->booked_count)->toBe(0);
    });

    $event = DB::table('shared_outbox_events')
        ->where('event_name', 'scheduling.AppointmentCancelled')
        ->where('tenant_id', $tenantId)
        ->first();
    expect($event)->not->toBeNull();
});

it('marks an appointment as no show and frees slot', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Specialist Consultation', 30, facilityId: $facilityId);

    $slotDate = CarbonImmutable::now()->addDays(6)->format('Y-m-d');
    $startTime = CarbonImmutable::parse("{$slotDate} 15:00:00");
    $endTime = CarbonImmutable::parse("{$slotDate} 15:30:00");

    $slotId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $resourceId, $typeId, $slotId, $slotDate, $startTime, $endTime): void {
        $slot = Slot::create($tenantId, $facilityId, $resourceId, $slotDate, $startTime, $endTime, 1, $typeId, $slotId);
        $slot->save();
    });

    $appointmentId = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: (string) Str::uuid(),
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $startTime->toIso8601String(),
        scheduledEnd: $endTime->toIso8601String(),
        slotId: $slotId,
    );

    TenantContextScope::run($tenantId, $facilityId, function () use ($appointmentId): void {
        app(CommandBus::class)->dispatch(new MarkAppointmentNoShow($appointmentId));
    });

    TenantContextScope::run($tenantId, $facilityId, function () use ($appointmentId, $slotId): void {
        $appointment = Appointment::query()->find($appointmentId);
        expect($appointment->status)->toBe(AppointmentStatus::NO_SHOW);

        $slot = Slot::query()->find($slotId);
        expect($slot->status)->toBe(SlotStatus::OPEN)
            ->and($slot->booked_count)->toBe(0);
    });

    $event = DB::table('shared_outbox_events')
        ->where('event_name', 'scheduling.AppointmentNoShowRecorded')
        ->where('tenant_id', $tenantId)
        ->first();
    expect($event)->not->toBeNull();
});

it('queries appointments via query bus', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Specialist Consultation', 30, facilityId: $facilityId);

    $start = CarbonImmutable::now()->addDay();
    $end = $start->addMinutes(30);

    $patientId = (string) Str::uuid();
    $appointmentId = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: $patientId,
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $start->toIso8601String(),
        scheduledEnd: $end->toIso8601String(),
        reason: 'Routine checkup',
    );

    TenantContextScope::run($tenantId, $facilityId, function () use ($appointmentId, $patientId, $resourceId): void {
        // Query by ID
        $detail = app(QueryBus::class)->dispatch(new GetAppointment($appointmentId));
        expect($detail)->toBeInstanceOf(AppointmentDetail::class)
            ->and($detail->id)->toBe($appointmentId)
            ->and($detail->patientId)->toBe($patientId)
            ->and($detail->reason)->toBe('Routine checkup');

        // Query by Patient
        $patientAppointments = app(QueryBus::class)->dispatch(new GetPatientAppointments($patientId));
        expect($patientAppointments)->toHaveCount(1)
            ->and($patientAppointments[0])->toBeInstanceOf(AppointmentSummary::class)
            ->and($patientAppointments[0]->id)->toBe($appointmentId);

        // Query by Resource
        $resourceAppointments = app(QueryBus::class)->dispatch(new GetResourceAppointments($resourceId));
        expect($resourceAppointments)->toHaveCount(1)
            ->and($resourceAppointments[0]->id)->toBe($appointmentId);
    });
});

it('enforces multi tenant rls isolation on appointments', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Specialist Consultation', 30, facilityId: $facilityId);

    $start = CarbonImmutable::now()->addDay();
    $end = $start->addMinutes(30);

    $appointmentId = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: (string) Str::uuid(),
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $start->toIso8601String(),
        scheduledEnd: $end->toIso8601String(),
    );

    // Create Tenant B
    $otherFixture = makeTenantWithUser(['sch.access', 'sch.appointment.manage']);
    $otherTenantId = (string) $otherFixture['tenant']->id;
    $otherFacilityId = (string) $otherFixture['facility']->id;

    test()->actingAs($otherFixture['user']);

    // In Tenant B, appointment from Tenant A must return null via query and throw on command
    TenantContextScope::run($otherTenantId, $otherFacilityId, function () use ($appointmentId): void {
        $detail = app(QueryBus::class)->dispatch(new GetAppointment($appointmentId));
        expect($detail)->toBeNull();

        expect(fn () => app(CommandBus::class)->dispatch(new ConfirmAppointment($appointmentId)))
            ->toThrow(AppointmentNotFoundException::class);
    });
});

it('enforces rbac permissions on appointment commands', function (): void {
    $unauthorizedFixture = makeTenantWithUser(['sch.access']);
    $unauthTenantId = (string) $unauthorizedFixture['tenant']->id;
    $unauthFacilityId = (string) $unauthorizedFixture['facility']->id;

    test()->actingAs($unauthorizedFixture['user']);

    $start = CarbonImmutable::now()->addDay();
    $end = $start->addMinutes(30);

    TenantContextScope::run($unauthTenantId, $unauthFacilityId, function () use ($start, $end): void {
        expect(fn () => app(CommandBus::class)->dispatch(new BookAppointment(
            patientId: (string) Str::uuid(),
            resourceId: (string) Str::uuid(),
            appointmentTypeId: (string) Str::uuid(),
            scheduledStart: $start->toIso8601String(),
            scheduledEnd: $end->toIso8601String(),
        )))->toThrow(AuthorizationException::class);
    });
});
