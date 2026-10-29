<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Facility-wide leave calendar entry (25-hr-and-workforce-domain.md §10).
 */
final readonly class LeaveCalendarEntry
{
    public function __construct(
        public string $leaveRequestId,
        public string $facilityId,
        public string $staffId,
        public ?string $staffNumber,
        public ?string $staffName,
        public string $leaveTypeCode,
        public string $startDate,
        public string $endDate,
        public float $days,
        public string $status,
    ) {}
}
