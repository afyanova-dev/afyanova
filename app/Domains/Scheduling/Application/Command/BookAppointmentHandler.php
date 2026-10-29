<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\Scheduling\Domain\AppointmentRepository;
use App\Domains\Scheduling\Domain\AppointmentTypeRepository;
use App\Domains\Scheduling\Domain\Exception\AppointmentConflictException;
use App\Domains\Scheduling\Domain\Exception\AppointmentSlotMismatchException;
use App\Domains\Scheduling\Domain\Model\Appointment;
use App\Domains\Scheduling\Domain\SchedulableResourceRepository;
use App\Domains\Scheduling\Domain\SlotRepository;
use App\Domains\Scheduling\Infrastructure\Sequence\AppointmentNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

final class BookAppointmentHandler
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly SchedulableResourceRepository $resources,
        private readonly AppointmentTypeRepository $appointmentTypes,
        private readonly SlotRepository $slots,
        private readonly AppointmentNumberSequence $numberSequence,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(BookAppointment $command): void
    {
        $tenantId = $this->context->tenantId();

        // Row lock: concurrent bookings against the same resource serialize
        // on this read inside the request transaction, so two simultaneous
        // requests cannot both pass the overlap check below.
        $resource = $this->resources->getByIdForUpdate($command->resourceId);
        $this->appointmentTypes->getById($command->appointmentTypeId);

        $facilityId = $command->facilityId ?? $this->context->facilityId() ?? (string) $resource->facility_id;

        $start = CarbonImmutable::parse($command->scheduledStart);
        $end = CarbonImmutable::parse($command->scheduledEnd);

        if ($command->slotId !== null) {
            $slot = $this->slots->getByIdForUpdate($command->slotId);

            if ((string) $slot->resource_id !== $command->resourceId) {
                throw AppointmentSlotMismatchException::forResourceMismatch(
                    $command->slotId,
                    (string) $slot->resource_id,
                    $command->resourceId
                );
            }

            if ($slot->appointment_type_id !== null && (string) $slot->appointment_type_id !== $command->appointmentTypeId) {
                throw AppointmentSlotMismatchException::forTypeMismatch(
                    $command->slotId,
                    (string) $slot->appointment_type_id,
                    $command->appointmentTypeId
                );
            }

            $slot->book();
            $this->slots->save($slot);

            $start = $slot->start_time;
            $end = $slot->end_time;
        } else {
            // Ad-hoc (slot-less) bookings hold no slot capacity, so the
            // interval itself must be free: same exclusion set as the
            // scheduling API availability check. Slot bookings skip this —
            // shared-capacity slots are governed by Slot::book() instead.
            if ($this->appointments->existsOverlapping($command->resourceId, $start, $end)) {
                throw AppointmentConflictException::forOverlappingInterval(
                    $command->resourceId,
                    $start->toIso8601String(),
                    $end->toIso8601String(),
                );
            }
        }

        $appointmentNumber = $this->numberSequence->next($tenantId);

        $appointment = Appointment::book(
            tenantId: $tenantId,
            facilityId: $facilityId,
            appointmentNumber: $appointmentNumber,
            patientId: $command->patientId,
            resourceId: $command->resourceId,
            appointmentTypeId: $command->appointmentTypeId,
            scheduledStart: $start,
            scheduledEnd: $end,
            slotId: $command->slotId,
            reason: $command->reason,
            appointmentId: $command->appointmentId,
        );

        $this->appointments->save($appointment);
    }
}
