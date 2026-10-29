<?php

declare(strict_types=1);

use App\Domains\Scheduling\Application\Command\AcceptWaitlistOffer;
use App\Domains\Scheduling\Application\Command\AddToWaitlist;
use App\Domains\Scheduling\Application\Command\CancelWaitlistEntry;
use App\Domains\Scheduling\Application\Command\DeclineWaitlistOffer;
use App\Domains\Scheduling\Application\Command\OfferSlotToWaitlist;
use App\Domains\Scheduling\Application\Query\GetFacilityWaitlist;
use App\Domains\Scheduling\Application\Query\GetWaitlistEntry;
use App\Domains\Scheduling\Contracts\Dto\AppointmentSummary;
use App\Domains\Scheduling\Contracts\Dto\WaitlistEntrySummary;
use App\Domains\Scheduling\Contracts\SchedulingApi;
use App\Domains\Scheduling\Domain\Exception\SlotNotAvailableException;
use App\Domains\Scheduling\Domain\Exception\WaitlistEntryNotFoundException;
use App\Domains\Scheduling\Domain\Exception\WaitlistOfferExpiredException;
use App\Domains\Scheduling\Domain\Model\Appointment;
use App\Domains\Scheduling\Domain\Model\Slot;
use App\Domains\Scheduling\Domain\Model\WaitlistEntry;
use App\Domains\Scheduling\Domain\ValueObject\AppointmentStatus;
use App\Domains\Scheduling\Domain\ValueObject\SlotStatus;
use App\Domains\Scheduling\Domain\ValueObject\WaitlistPriority;
use App\Domains\Scheduling\Domain\ValueObject\WaitlistStatus;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/scheduling.php';

it('adds a patient to waitlist and allocates monotonic WTL-%08d number', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CAR', 'Cardiology Consult', 30, facilityId: $facilityId);
    $patientId = (string) Str::uuid();

    $entryId = TenantContextScope::run($tenantId, $facilityId, function () use ($patientId, $typeId): string {
        return app(CommandBus::class)->dispatch(new AddToWaitlist(
            patientId: $patientId,
            appointmentTypeId: $typeId,
            priority: 'URGENT',
            preferredDateStart: CarbonImmutable::now()->addDays(2)->format('Y-m-d'),
            preferredDateEnd: CarbonImmutable::now()->addDays(10)->format('Y-m-d'),
            notes: 'Follow-up for chest tightness',
        ));
    });

    expect($entryId)->toBeString()->not->toBeEmpty();

    TenantContextScope::run($tenantId, $facilityId, function () use ($entryId, $patientId, $typeId): void {
        $entry = WaitlistEntry::query()->find($entryId);
        expect($entry)->not->toBeNull()
            ->and($entry->waitlist_number)->toBe('WTL-00000001')
            ->and($entry->patient_id)->toBe($patientId)
            ->and($entry->appointment_type_id)->toBe($typeId)
            ->and($entry->priority)->toBe(WaitlistPriority::URGENT)
            ->and($entry->status)->toBe(WaitlistStatus::ACTIVE)
            ->and($entry->notes)->toBe('Follow-up for chest tightness');
    });

    $event = DB::table('shared_outbox_events')
        ->where('event_name', 'scheduling.WaitlistEntryAdded')
        ->where('tenant_id', $tenantId)
        ->first();
    expect($event)->not->toBeNull();
});

it('orders facility waitlist by priority (EMERGENCY > URGENT > ROUTINE) and FIFO (Doc 22 §115-§117)', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $typeId = defineAppointmentTypeHelper($tenantId, 'GEN-OPD', 'General OPD', 20, facilityId: $facilityId);

    // Add 1st: Routine
    $r1 = TenantContextScope::run($tenantId, $facilityId, fn (): string => app(CommandBus::class)->dispatch(new AddToWaitlist(
        patientId: (string) Str::uuid(),
        appointmentTypeId: $typeId,
        priority: 'ROUTINE',
        notes: 'Routine 1',
    )));

    // Add 2nd: Urgent
    $u1 = TenantContextScope::run($tenantId, $facilityId, fn (): string => app(CommandBus::class)->dispatch(new AddToWaitlist(
        patientId: (string) Str::uuid(),
        appointmentTypeId: $typeId,
        priority: 'URGENT',
        notes: 'Urgent 1',
    )));

    // Add 3rd: Emergency
    $e1 = TenantContextScope::run($tenantId, $facilityId, fn (): string => app(CommandBus::class)->dispatch(new AddToWaitlist(
        patientId: (string) Str::uuid(),
        appointmentTypeId: $typeId,
        priority: 'EMERGENCY',
        notes: 'Emergency 1',
    )));

    // Add 4th: Urgent (later than Urgent 1)
    $u2 = TenantContextScope::run($tenantId, $facilityId, fn (): string => app(CommandBus::class)->dispatch(new AddToWaitlist(
        patientId: (string) Str::uuid(),
        appointmentTypeId: $typeId,
        priority: 'URGENT',
        notes: 'Urgent 2',
    )));

    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId, $e1, $u1, $u2, $r1): void {
        $list = app(QueryBus::class)->dispatch(new GetFacilityWaitlist($facilityId));

        expect($list)->toHaveCount(4);
        // Canonical priority ordering: Emergency first, then Urgent (FIFO: u1 then u2), then Routine (r1)
        expect($list[0]->id)->toBe($e1)
            ->and($list[1]->id)->toBe($u1)
            ->and($list[2]->id)->toBe($u2)
            ->and($list[3]->id)->toBe($r1);
    });
});

it('offers an open slot to a waitlist candidate and holds the slot', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Consultation', 30, facilityId: $facilityId);

    $slotDate = CarbonImmutable::now()->addDays(3)->format('Y-m-d');
    $startTime = CarbonImmutable::parse("{$slotDate} 10:00:00");
    $endTime = CarbonImmutable::parse("{$slotDate} 10:30:00");

    $slotId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $resourceId, $typeId, $slotId, $slotDate, $startTime, $endTime): void {
        $slot = Slot::create($tenantId, $facilityId, $resourceId, $slotDate, $startTime, $endTime, 1, $typeId, $slotId);
        $slot->save();
    });

    $entryId = TenantContextScope::run($tenantId, $facilityId, fn (): string => app(CommandBus::class)->dispatch(new AddToWaitlist(
        patientId: (string) Str::uuid(),
        appointmentTypeId: $typeId,
    )));

    $offeredUntil = CarbonImmutable::now()->addHours(2);
    TenantContextScope::run($tenantId, $facilityId, function () use ($entryId, $slotId, $offeredUntil): void {
        app(CommandBus::class)->dispatch(new OfferSlotToWaitlist(
            waitlistEntryId: $entryId,
            slotId: $slotId,
            offeredUntil: $offeredUntil->toIso8601String(),
        ));
    });

    TenantContextScope::run($tenantId, $facilityId, function () use ($entryId, $slotId): void {
        $entry = WaitlistEntry::query()->find($entryId);
        expect($entry->status)->toBe(WaitlistStatus::OFFERED)
            ->and($entry->offered_slot_id)->toBe($slotId)
            ->and($entry->offered_until)->not->toBeNull();

        $slot = Slot::query()->find($slotId);
        expect($slot->status)->toBe(SlotStatus::HELD)
            ->and($slot->held_by)->toBe('WAITLIST_OFFER');
    });

    $event = DB::table('shared_outbox_events')
        ->where('event_name', 'scheduling.WaitlistSlotOffered')
        ->where('tenant_id', $tenantId)
        ->first();
    expect($event)->not->toBeNull();
});

it('rejects offering an unavailable slot to a waitlist candidate', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Consultation', 30, facilityId: $facilityId);

    $slotDate = CarbonImmutable::now()->addDays(3)->format('Y-m-d');
    $startTime = CarbonImmutable::parse("{$slotDate} 11:00:00");
    $endTime = CarbonImmutable::parse("{$slotDate} 11:30:00");

    $slotId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $resourceId, $typeId, $slotId, $slotDate, $startTime, $endTime): void {
        $slot = Slot::create($tenantId, $facilityId, $resourceId, $slotDate, $startTime, $endTime, 1, $typeId, $slotId);
        $slot->block('Maintenance');
        $slot->save();
    });

    $entryId = TenantContextScope::run($tenantId, $facilityId, fn (): string => app(CommandBus::class)->dispatch(new AddToWaitlist(
        patientId: (string) Str::uuid(),
        appointmentTypeId: $typeId,
    )));

    TenantContextScope::run($tenantId, $facilityId, function () use ($entryId, $slotId): void {
        expect(fn () => app(CommandBus::class)->dispatch(new OfferSlotToWaitlist(
            waitlistEntryId: $entryId,
            slotId: $slotId,
            offeredUntil: CarbonImmutable::now()->addHours(1)->toIso8601String(),
        )))->toThrow(SlotNotAvailableException::class);
    });
});

it('accepts a waitlist offer, converts atomically to booked appointment, and frees hold', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Consultation', 30, facilityId: $facilityId);

    $slotDate = CarbonImmutable::now()->addDays(3)->format('Y-m-d');
    $startTime = CarbonImmutable::parse("{$slotDate} 12:00:00");
    $endTime = CarbonImmutable::parse("{$slotDate} 12:30:00");

    $slotId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $resourceId, $typeId, $slotId, $slotDate, $startTime, $endTime): void {
        $slot = Slot::create($tenantId, $facilityId, $resourceId, $slotDate, $startTime, $endTime, 1, $typeId, $slotId);
        $slot->save();
    });

    $patientId = (string) Str::uuid();
    $entryId = TenantContextScope::run($tenantId, $facilityId, fn (): string => app(CommandBus::class)->dispatch(new AddToWaitlist(
        patientId: $patientId,
        appointmentTypeId: $typeId,
        notes: 'Priority specialist referral',
    )));

    TenantContextScope::run($tenantId, $facilityId, function () use ($entryId, $slotId): void {
        app(CommandBus::class)->dispatch(new OfferSlotToWaitlist(
            waitlistEntryId: $entryId,
            slotId: $slotId,
            offeredUntil: CarbonImmutable::now()->addHours(3)->toIso8601String(),
        ));
    });

    $appointmentId = TenantContextScope::run($tenantId, $facilityId, function () use ($entryId): string {
        return app(CommandBus::class)->dispatch(new AcceptWaitlistOffer(
            waitlistEntryId: $entryId,
        ));
    });

    expect($appointmentId)->toBeString()->not->toBeEmpty();

    TenantContextScope::run($tenantId, $facilityId, function () use ($entryId, $slotId, $appointmentId, $patientId): void {
        $entry = WaitlistEntry::query()->find($entryId);
        expect($entry->status)->toBe(WaitlistStatus::BOOKED)
            ->and($entry->booked_appointment_id)->toBe($appointmentId);

        $appointment = Appointment::query()->find($appointmentId);
        expect($appointment)->not->toBeNull()
            ->and($appointment->patient_id)->toBe($patientId)
            ->and($appointment->status)->toBe(AppointmentStatus::BOOKED)
            ->and($appointment->slot_id)->toBe($slotId);

        $slot = Slot::query()->find($slotId);
        expect($slot->booked_count)->toBe(1)
            ->and($slot->status)->toBe(SlotStatus::BOOKED);
    });

    $event = DB::table('shared_outbox_events')
        ->where('event_name', 'scheduling.WaitlistOfferAccepted')
        ->where('tenant_id', $tenantId)
        ->first();
    expect($event)->not->toBeNull();
});

it('rejects accepting an expired waitlist offer', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Consultation', 30, facilityId: $facilityId);

    $slotDate = CarbonImmutable::now()->addDays(3)->format('Y-m-d');
    $startTime = CarbonImmutable::parse("{$slotDate} 13:00:00");
    $endTime = CarbonImmutable::parse("{$slotDate} 13:30:00");

    $slotId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $resourceId, $typeId, $slotId, $slotDate, $startTime, $endTime): void {
        $slot = Slot::create($tenantId, $facilityId, $resourceId, $slotDate, $startTime, $endTime, 1, $typeId, $slotId);
        $slot->save();
    });

    $entryId = TenantContextScope::run($tenantId, $facilityId, fn (): string => app(CommandBus::class)->dispatch(new AddToWaitlist(
        patientId: (string) Str::uuid(),
        appointmentTypeId: $typeId,
    )));

    // Offer slot with expiration in the past
    TenantContextScope::run($tenantId, $facilityId, function () use ($entryId, $slotId): void {
        app(CommandBus::class)->dispatch(new OfferSlotToWaitlist(
            waitlistEntryId: $entryId,
            slotId: $slotId,
            offeredUntil: CarbonImmutable::now()->subMinutes(5)->toIso8601String(),
        ));
    });

    TenantContextScope::run($tenantId, $facilityId, function () use ($entryId): void {
        expect(fn () => app(CommandBus::class)->dispatch(new AcceptWaitlistOffer($entryId)))
            ->toThrow(WaitlistOfferExpiredException::class);
    });
});

it('declines a waitlist offer, releases slot hold, and reverts entry to ACTIVE', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Consultation', 30, facilityId: $facilityId);

    $slotDate = CarbonImmutable::now()->addDays(3)->format('Y-m-d');
    $startTime = CarbonImmutable::parse("{$slotDate} 14:00:00");
    $endTime = CarbonImmutable::parse("{$slotDate} 14:30:00");

    $slotId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $resourceId, $typeId, $slotId, $slotDate, $startTime, $endTime): void {
        $slot = Slot::create($tenantId, $facilityId, $resourceId, $slotDate, $startTime, $endTime, 1, $typeId, $slotId);
        $slot->save();
    });

    $entryId = TenantContextScope::run($tenantId, $facilityId, fn (): string => app(CommandBus::class)->dispatch(new AddToWaitlist(
        patientId: (string) Str::uuid(),
        appointmentTypeId: $typeId,
    )));

    TenantContextScope::run($tenantId, $facilityId, function () use ($entryId, $slotId): void {
        app(CommandBus::class)->dispatch(new OfferSlotToWaitlist(
            waitlistEntryId: $entryId,
            slotId: $slotId,
            offeredUntil: CarbonImmutable::now()->addHours(2)->toIso8601String(),
        ));
    });

    TenantContextScope::run($tenantId, $facilityId, function () use ($entryId): void {
        app(CommandBus::class)->dispatch(new DeclineWaitlistOffer(
            waitlistEntryId: $entryId,
            reason: 'Patient unavailable at this time',
        ));
    });

    TenantContextScope::run($tenantId, $facilityId, function () use ($entryId, $slotId): void {
        $entry = WaitlistEntry::query()->find($entryId);
        expect($entry->status)->toBe(WaitlistStatus::ACTIVE)
            ->and($entry->offered_slot_id)->toBeNull();

        $slot = Slot::query()->find($slotId);
        expect($slot->status)->toBe(SlotStatus::OPEN)
            ->and($slot->held_by)->toBeNull();
    });

    $event = DB::table('shared_outbox_events')
        ->where('event_name', 'scheduling.WaitlistOfferDeclined')
        ->where('tenant_id', $tenantId)
        ->first();
    expect($event)->not->toBeNull();
});

it('cancels a waitlist entry and frees offered slot if held', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Consultation', 30, facilityId: $facilityId);

    $slotDate = CarbonImmutable::now()->addDays(3)->format('Y-m-d');
    $startTime = CarbonImmutable::parse("{$slotDate} 15:00:00");
    $endTime = CarbonImmutable::parse("{$slotDate} 15:30:00");

    $slotId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $resourceId, $typeId, $slotId, $slotDate, $startTime, $endTime): void {
        $slot = Slot::create($tenantId, $facilityId, $resourceId, $slotDate, $startTime, $endTime, 1, $typeId, $slotId);
        $slot->save();
    });

    $entryId = TenantContextScope::run($tenantId, $facilityId, fn (): string => app(CommandBus::class)->dispatch(new AddToWaitlist(
        patientId: (string) Str::uuid(),
        appointmentTypeId: $typeId,
    )));

    TenantContextScope::run($tenantId, $facilityId, function () use ($entryId, $slotId): void {
        app(CommandBus::class)->dispatch(new OfferSlotToWaitlist(
            waitlistEntryId: $entryId,
            slotId: $slotId,
            offeredUntil: CarbonImmutable::now()->addHours(2)->toIso8601String(),
        ));
    });

    TenantContextScope::run($tenantId, $facilityId, function () use ($entryId): void {
        app(CommandBus::class)->dispatch(new CancelWaitlistEntry(
            waitlistEntryId: $entryId,
            reason: 'Patient relocated',
        ));
    });

    TenantContextScope::run($tenantId, $facilityId, function () use ($entryId, $slotId): void {
        $entry = WaitlistEntry::query()->find($entryId);
        expect($entry->status)->toBe(WaitlistStatus::CANCELLED)
            ->and($entry->cancellation_reason)->toBe('Patient relocated');

        $slot = Slot::query()->find($slotId);
        expect($slot->status)->toBe(SlotStatus::OPEN)
            ->and($slot->held_by)->toBeNull();
    });

    $event = DB::table('shared_outbox_events')
        ->where('event_name', 'scheduling.WaitlistEntryCancelled')
        ->where('tenant_id', $tenantId)
        ->first();
    expect($event)->not->toBeNull();
});

it('sweeps lapsed waitlist offers and releases held slots via console command', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Consultation', 30, facilityId: $facilityId);

    $slotDate = CarbonImmutable::now()->addDays(3)->format('Y-m-d');
    $startTime = CarbonImmutable::parse("{$slotDate} 16:00:00");
    $endTime = CarbonImmutable::parse("{$slotDate} 16:30:00");

    $slotId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $resourceId, $typeId, $slotId, $slotDate, $startTime, $endTime): void {
        $slot = Slot::create($tenantId, $facilityId, $resourceId, $slotDate, $startTime, $endTime, 1, $typeId, $slotId);
        $slot->save();
    });

    $entryId = TenantContextScope::run($tenantId, $facilityId, fn (): string => app(CommandBus::class)->dispatch(new AddToWaitlist(
        patientId: (string) Str::uuid(),
        appointmentTypeId: $typeId,
    )));

    // Offer slot with expiration in the past
    TenantContextScope::run($tenantId, $facilityId, function () use ($entryId, $slotId): void {
        app(CommandBus::class)->dispatch(new OfferSlotToWaitlist(
            waitlistEntryId: $entryId,
            slotId: $slotId,
            offeredUntil: CarbonImmutable::now()->subMinutes(10)->toIso8601String(),
        ));
    });

    // Run sweep console command
    $exitCode = Artisan::call('scheduling:expire-waitlist-offers', ['--once' => true]);
    expect($exitCode)->toBe(0);

    TenantContextScope::run($tenantId, $facilityId, function () use ($entryId, $slotId): void {
        $entry = WaitlistEntry::query()->find($entryId);
        expect($entry->status)->toBe(WaitlistStatus::ACTIVE)
            ->and($entry->offered_slot_id)->toBeNull();

        $slot = Slot::query()->find($slotId);
        expect($slot->status)->toBe(SlotStatus::OPEN)
            ->and($slot->held_by)->toBeNull();
    });
});

it('sweeps lapsed slot holds via scheduling:expire-slot-holds', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Consultation', 30, facilityId: $facilityId);

    $slotDate = CarbonImmutable::now()->addDays(3)->format('Y-m-d');
    $startTime = CarbonImmutable::parse("{$slotDate} 17:00:00");
    $endTime = CarbonImmutable::parse("{$slotDate} 17:30:00");

    $slotId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $resourceId, $typeId, $slotId, $slotDate, $startTime, $endTime): void {
        $slot = Slot::create($tenantId, $facilityId, $resourceId, $slotDate, $startTime, $endTime, 1, $typeId, $slotId);
        $slot->hold('M_PESA_RESERVATION', CarbonImmutable::now()->subMinutes(15));
        $slot->save();
    });

    // Run sweep console command
    $exitCode = Artisan::call('scheduling:expire-slot-holds', ['--once' => true]);
    expect($exitCode)->toBe(0);

    TenantContextScope::run($tenantId, $facilityId, function () use ($slotId): void {
        $slot = Slot::query()->find($slotId);
        expect($slot->status)->toBe(SlotStatus::OPEN)
            ->and($slot->held_by)->toBeNull();
    });
});

it('integrates via public SchedulingApi contract port', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Consultation', 30, facilityId: $facilityId);

    $patientId = (string) Str::uuid();
    $start = CarbonImmutable::now()->addDays(2)->setHour(10)->setMinute(0)->setSecond(0);
    $end = $start->addMinutes(30);

    $appointmentId = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: $patientId,
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $start->toIso8601String(),
        scheduledEnd: $end->toIso8601String(),
    );

    $entryId = TenantContextScope::run($tenantId, $facilityId, fn (): string => app(CommandBus::class)->dispatch(new AddToWaitlist(
        patientId: $patientId,
        appointmentTypeId: $typeId,
        priority: 'ROUTINE',
    )));

    TenantContextScope::run($tenantId, $facilityId, function () use ($appointmentId, $patientId, $resourceId, $start, $end, $entryId): void {
        /** @var SchedulingApi $api */
        $api = app(SchedulingApi::class);

        // 1. getAppointmentSummary
        $summary = $api->getAppointmentSummary($appointmentId);
        expect($summary)->toBeInstanceOf(AppointmentSummary::class)
            ->and($summary->id)->toBe($appointmentId)
            ->and($summary->patientId)->toBe($patientId);

        // 2. getPatientUpcomingAppointments
        $upcoming = $api->getPatientUpcomingAppointments($patientId);
        expect($upcoming)->toHaveCount(1)
            ->and($upcoming[0]->id)->toBe($appointmentId);

        // 3. isResourceAvailable: conflict in same window
        expect($api->isResourceAvailable($resourceId, $start, $end))->toBeFalse();
        // Free window:
        expect($api->isResourceAvailable($resourceId, $start->addHours(2), $end->addHours(2)))->toBeTrue();

        // 4. getPatientWaitlistEntries
        $waitlist = $api->getPatientWaitlistEntries($patientId);
        expect($waitlist)->toHaveCount(1)
            ->and($waitlist[0])->toBeInstanceOf(WaitlistEntrySummary::class)
            ->and($waitlist[0]->id)->toBe($entryId);
    });
});

it('enforces multi-tenant RLS isolation on waitlist entries', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Consultation', 30, facilityId: $facilityId);

    $entryId = TenantContextScope::run($tenantId, $facilityId, fn (): string => app(CommandBus::class)->dispatch(new AddToWaitlist(
        patientId: (string) Str::uuid(),
        appointmentTypeId: $typeId,
    )));

    // Create Tenant B
    $otherFixture = makeTenantWithUser(['sch.access', 'sch.waitlist.manage']);
    $otherTenantId = (string) $otherFixture['tenant']->id;
    $otherFacilityId = (string) $otherFixture['facility']->id;

    test()->actingAs($otherFixture['user']);

    // In Tenant B, waitlist entry from Tenant A must return null via query and throw on command
    TenantContextScope::run($otherTenantId, $otherFacilityId, function () use ($entryId): void {
        $detail = app(QueryBus::class)->dispatch(new GetWaitlistEntry($entryId));
        expect($detail)->toBeNull();

        expect(fn () => app(CommandBus::class)->dispatch(new CancelWaitlistEntry($entryId)))
            ->toThrow(WaitlistEntryNotFoundException::class);
    });
});

it('enforces RBAC permissions on waitlist commands', function (): void {
    $unauthorizedFixture = makeTenantWithUser(['sch.access']);
    $unauthTenantId = (string) $unauthorizedFixture['tenant']->id;
    $unauthFacilityId = (string) $unauthorizedFixture['facility']->id;

    test()->actingAs($unauthorizedFixture['user']);

    TenantContextScope::run($unauthTenantId, $unauthFacilityId, function (): void {
        expect(fn () => app(CommandBus::class)->dispatch(new AddToWaitlist(
            patientId: (string) Str::uuid(),
            appointmentTypeId: (string) Str::uuid(),
        )))->toThrow(AuthorizationException::class);
    });
});
