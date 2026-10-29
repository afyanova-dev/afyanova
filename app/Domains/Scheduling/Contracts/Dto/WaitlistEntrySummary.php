<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Dto;

/**
 * Summary projection for a waitlist entry.
 */
final readonly class WaitlistEntrySummary
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $facilityId,
        public string $waitlistNumber,
        public string $patientId,
        public string $appointmentTypeId,
        public ?string $resourceId,
        public string $priority,
        public string $status,
        public ?string $preferredDateStart,
        public ?string $preferredDateEnd,
        public ?string $notes,
        public ?string $offeredSlotId,
        public ?string $offeredAt,
        public ?string $offeredUntil,
        public ?string $bookedAppointmentId,
        public string $createdAt,
    ) {}
}
