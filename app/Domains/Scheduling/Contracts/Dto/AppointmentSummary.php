<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Dto;

final readonly class AppointmentSummary
{
    public function __construct(
        public string $id,
        public string $facilityId,
        public string $appointmentNumber,
        public ?string $slotId,
        public string $patientId,
        public string $resourceId,
        public string $appointmentTypeId,
        public string $scheduledStart,
        public string $scheduledEnd,
        public string $status,
        public ?string $reason,
    ) {}
}
