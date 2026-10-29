<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Aggregated staff timesheet summary for payroll hand-off (25-hr-and-workforce-domain.md §6).
 */
final readonly class TimesheetSummary
{
    /**
     * @param  list<TimesheetDaySummary>  $days
     */
    public function __construct(
        public string $staffId,
        public ?string $staffNumber,
        public ?string $staffName,
        public string $facilityId,
        public string $startDate,
        public string $endDate,
        public int $totalPlannedMinutes,
        public int $totalWorkedMinutes,
        public int $totalBreakMinutes,
        public int $totalOvertimeMinutes,
        public array $days,
    ) {}
}
