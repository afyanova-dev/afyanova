<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Event;

/**
 * Emitted when an appointment is rescheduled (22-scheduling-and-appointments-domain.md §48, ADR-207).
 */
final readonly class AppointmentRescheduled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $appointmentId,
        public string $appointmentNumber,
        public ?string $oldSlotId,
        public ?string $newSlotId,
        public string $oldScheduledStart,
        public string $newScheduledStart,
        public string $newScheduledEnd,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
