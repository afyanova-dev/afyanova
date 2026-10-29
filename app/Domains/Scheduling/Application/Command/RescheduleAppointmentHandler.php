<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\Scheduling\Domain\AppointmentRepository;
use App\Domains\Scheduling\Domain\Exception\AppointmentConflictException;
use App\Domains\Scheduling\Domain\Exception\AppointmentSlotMismatchException;
use App\Domains\Scheduling\Domain\SchedulableResourceRepository;
use App\Domains\Scheduling\Domain\SlotRepository;
use Carbon\CarbonImmutable;

final class RescheduleAppointmentHandler
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly SlotRepository $slots,
        private readonly SchedulableResourceRepository $resources,
    ) {}

    public function __invoke(RescheduleAppointment $command): void
    {
        $appointment = $this->appointments->getByIdForUpdate($command->appointmentId);

        $newStart = CarbonImmutable::parse($command->newScheduledStart);
        $newEnd = CarbonImmutable::parse($command->newScheduledEnd);

        // 1. Release previous slot booking if one was assigned
        if ($appointment->slot_id !== null && $appointment->slot_id !== $command->newSlotId) {
            $oldSlot = $this->slots->getByIdForUpdate($appointment->slot_id);
            $oldSlot->cancelBooking();
            $this->slots->save($oldSlot);
        }

        // 2. Claim new slot if assigned
        if ($command->newSlotId !== null && $command->newSlotId !== $appointment->slot_id) {
            $newSlot = $this->slots->getByIdForUpdate($command->newSlotId);

            if ((string) $newSlot->resource_id !== (string) $appointment->resource_id) {
                throw AppointmentSlotMismatchException::forResourceMismatch(
                    $command->newSlotId,
                    (string) $newSlot->resource_id,
                    (string) $appointment->resource_id
                );
            }

            if ($newSlot->appointment_type_id !== null && (string) $newSlot->appointment_type_id !== (string) $appointment->appointment_type_id) {
                throw AppointmentSlotMismatchException::forTypeMismatch(
                    $command->newSlotId,
                    (string) $newSlot->appointment_type_id,
                    (string) $appointment->appointment_type_id
                );
            }

            $newSlot->book();
            $this->slots->save($newSlot);

            $newStart = $newSlot->start_time;
            $newEnd = $newSlot->end_time;
        } else {
            // Ad-hoc move: the interval itself must be free of OTHER
            // appointments (self excluded — shifting within its own current
            // interval is not a conflict). Row lock serializes this against
            // concurrent bookings. Slot moves stay capacity-governed above.
            $resourceId = (string) $appointment->resource_id;
            $this->resources->getByIdForUpdate($resourceId);
            if ($this->appointments->existsOverlappingExcluding($resourceId, $newStart, $newEnd, $command->appointmentId)) {
                throw AppointmentConflictException::forOverlappingInterval(
                    $resourceId,
                    $newStart->toIso8601String(),
                    $newEnd->toIso8601String(),
                );
            }
        }

        $appointment->reschedule($command->newSlotId, $newStart, $newEnd);

        $this->appointments->save($appointment);
    }
}
