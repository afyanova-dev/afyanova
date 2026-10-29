<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Daily summary within a staff timesheet (25-hr-and-workforce-domain.md §6).
 */
final readonly class TimesheetDaySummary
{
    /**
     * @param  list<string>  $sessionIds
     * @param  list<string>  $rosterEntryIds
     */
    public function __construct(
        public string $date,
        public int $plannedMinutes,
        public int $workedMinutes,
        public int $breakMinutes,
        public int $overtimeMinutes,
        public string $status,
        public string $exception,
        public array $sessionIds,
        public array $rosterEntryIds,
    ) {}
}
