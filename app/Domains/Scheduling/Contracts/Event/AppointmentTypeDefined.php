<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Event;

/**
 * Emitted when an appointment type is defined (22-scheduling-and-appointments-domain.md §7).
 */
final readonly class AppointmentTypeDefined
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $appointmentTypeId,
        public ?string $facilityId,
        public string $code,
        public string $name,
        public int $durationMinutes,
        public int $bufferBeforeMinutes,
        public int $bufferAfterMinutes,
        public bool $isVirtual,
        public bool $allowPatientBooking,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
