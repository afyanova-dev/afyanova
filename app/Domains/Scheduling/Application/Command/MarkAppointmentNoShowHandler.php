<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\Scheduling\Domain\AppointmentRepository;
use App\Domains\Scheduling\Domain\SlotRepository;

final class MarkAppointmentNoShowHandler
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly SlotRepository $slots,
    ) {}

    public function __invoke(MarkAppointmentNoShow $command): void
    {
        $appointment = $this->appointments->getByIdForUpdate($command->appointmentId);

        if ($appointment->slot_id !== null) {
            $slot = $this->slots->getByIdForUpdate($appointment->slot_id);
            $slot->cancelBooking();
            $this->slots->save($slot);
        }

        $appointment->markNoShow();

        $this->appointments->save($appointment);
    }
}
