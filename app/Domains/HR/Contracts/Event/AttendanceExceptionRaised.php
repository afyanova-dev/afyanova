<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when an attendance exception is flagged on a session (25-hr-and-workforce-domain.md §6).
 */
final readonly class AttendanceExceptionRaised
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $attendanceSessionId,
        public string $facilityId,
        public string $staffId,
        public string $exceptionType,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
