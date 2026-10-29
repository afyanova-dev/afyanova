<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Event;

/**
 * Emitted when a patient is added to the waitlist (22-scheduling-and-appointments-domain.md §23–§27).
 */
final readonly class WaitlistEntryAdded
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $waitlistEntryId,
        public string $waitlistNumber,
        public string $patientId,
        public string $appointmentTypeId,
        public ?string $resourceId,
        public string $priority,
        public ?string $preferredDateStart,
        public ?string $preferredDateEnd,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
