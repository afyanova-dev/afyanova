<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Event;

/**
 * Emitted when patient arrival is recorded for an appointment (ADR-104, ADR-207).
 *
 * NOTE: Appointment arrival is NOT encounter check-in. The Encounter domain
 * owns attendance via CreateEncounter; this event notifies reception.
 */
final readonly class AppointmentArrived
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $appointmentId,
        public string $appointmentNumber,
        public string $patientId,
        public string $arrivedAt,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
