<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when an attendance session is reconciled by a supervisor (25-hr-and-workforce-domain.md §6).
 */
final readonly class AttendanceSessionReconciled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $attendanceSessionId,
        public string $facilityId,
        public string $staffId,
        public string $reconciledBy,
        public string $reconciliationNote,
        public int $workedMinutes,
        public string $exception,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
