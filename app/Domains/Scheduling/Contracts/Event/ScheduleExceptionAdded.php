<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Event;

/**
 * Emitted when a schedule exception is recorded (22-scheduling-and-appointments-domain.md §40).
 */
final readonly class ScheduleExceptionAdded
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $exceptionId,
        public string $facilityId,
        public string $resourceId,
        public string $exceptionDate,
        public ?string $startTime,
        public ?string $endTime,
        public bool $isAvailable,
        public string $reason,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
