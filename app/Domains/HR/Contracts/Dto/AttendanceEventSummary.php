<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Attendance event summary (25-hr-and-workforce-domain.md §6).
 */
final readonly class AttendanceEventSummary
{
    /**
     * @param  array<string, mixed>|null  $rawPayload
     */
    public function __construct(
        public string $attendanceEventId,
        public string $tenantId,
        public string $facilityId,
        public string $staffId,
        public ?string $staffNumber,
        public ?string $staffName,
        public string $source,
        public ?string $deviceRef,
        public ?string $externalEventId,
        public string $direction,
        public string $occurredAt,
        public string $ingestedAt,
        public ?array $rawPayload,
        public ?string $notes,
        public int $version,
    ) {}
}
