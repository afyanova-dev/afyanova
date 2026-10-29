<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Attendance session summary (25-hr-and-workforce-domain.md §6).
 */
final readonly class AttendanceSessionSummary
{
    /**
     * @param  list<string>  $sourceEventIds
     */
    public function __construct(
        public string $attendanceSessionId,
        public string $tenantId,
        public string $facilityId,
        public string $staffId,
        public ?string $staffNumber,
        public ?string $staffName,
        public ?string $rosterEntryId,
        public string $startedAt,
        public ?string $endedAt,
        public int $breakMinutes,
        public int $workedMinutes,
        public string $exception,
        public array $sourceEventIds,
        public string $status,
        public ?string $reconciliationNote,
        public ?string $reconciledBy,
        public ?string $reconciledAt,
        public int $version,
    ) {}
}
