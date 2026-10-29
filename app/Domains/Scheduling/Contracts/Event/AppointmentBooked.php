<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Event;

/**
 * Emitted when an appointment is booked (22-scheduling-and-appointments-domain.md §48, ADR-207).
 */
final readonly class AppointmentBooked
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $appointmentId,
        public string $appointmentNumber,
        public ?string $slotId,
        public string $patientId,
        public string $resourceId,
        public string $appointmentTypeId,
        public string $scheduledStart,
        public string $scheduledEnd,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
