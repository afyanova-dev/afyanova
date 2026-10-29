<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when an attendance clocking event is ingested (25-hr-and-workforce-domain.md §6).
 */
final readonly class AttendanceEventIngested
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $attendanceEventId,
        public string $facilityId,
        public string $staffId,
        public string $source,
        public string $direction,
        public string $occurredAt,
        public string $ingestedAt,
        public ?string $externalEventId = null,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
