<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Event;

/**
 * Emitted when a recurring schedule is defined for a resource (22-scheduling-and-appointments-domain.md §37).
 */
final readonly class ScheduleDefined
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $scheduleId,
        public string $facilityId,
        public string $resourceId,
        public ?string $appointmentTypeId,
        public int $dayOfWeek,
        public string $startTime,
        public string $endTime,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
