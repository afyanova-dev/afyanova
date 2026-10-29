<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\Scheduling\Domain\AppointmentRepository;
use Carbon\CarbonImmutable;

final class RecordAppointmentArrivalHandler
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
    ) {}

    public function __invoke(RecordAppointmentArrival $command): void
    {
        $appointment = $this->appointments->getByIdForUpdate($command->appointmentId);

        $arrivedAt = $command->arrivedAt !== null
            ? CarbonImmutable::parse($command->arrivedAt)
            : CarbonImmutable::now();

        $appointment->recordArrival($arrivedAt);

        $this->appointments->save($appointment);
    }
}
