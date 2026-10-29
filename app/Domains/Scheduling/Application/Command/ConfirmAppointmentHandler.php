<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\Scheduling\Domain\AppointmentRepository;

final class ConfirmAppointmentHandler
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
    ) {}

    public function __invoke(ConfirmAppointment $command): void
    {
        $appointment = $this->appointments->getByIdForUpdate($command->appointmentId);

        $appointment->confirm();

        $this->appointments->save($appointment);
    }
}
