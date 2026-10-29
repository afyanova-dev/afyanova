<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Event;

/**
 * Emitted when an appointment is cancelled (22-scheduling-and-appointments-domain.md §48, ADR-207).
 */
final readonly class AppointmentCancelled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $appointmentId,
        public string $appointmentNumber,
        public ?string $slotId,
        public ?string $reason,
        public ?string $cancelledBy,
        public string $cancelledAt,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
