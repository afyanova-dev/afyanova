<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Dto;

final readonly class AppointmentDetail
{
    public function __construct(
        public string $id,
        public string $tenantId,
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
        public ?string $cancellationReason,
        public ?string $cancelledBy,
        public ?string $cancelledAt,
        public ?string $arrivedAt,
        public ?string $startedAt,
        public ?string $completedAt,
        public int $version,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
