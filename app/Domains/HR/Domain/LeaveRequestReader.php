<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Contracts\Dto\LeaveCalendarEntry;
use App\Domains\HR\Contracts\Dto\LeaveRequestSummary;
use Carbon\CarbonImmutable;

/**
 * Read-side port for LeaveRequest queries (25-hr-and-workforce-domain.md §6, §10).
 */
interface LeaveRequestReader
{
    public function summary(string $leaveRequestId): LeaveRequestSummary;

    /**
     * @return list<LeaveRequestSummary>
     */
    public function forStaff(string $staffId, ?string $status = null): array;

    /**
     * @return list<LeaveRequestSummary>
     */
    public function forFacility(string $facilityId, ?string $status = null): array;

    /**
     * @return list<LeaveCalendarEntry>
     */
    public function getCalendar(string $facilityId, CarbonImmutable $startDate, CarbonImmutable $endDate): array;

    public function getActiveLeaveForStaffOnDate(string $staffId, CarbonImmutable $date): ?LeaveRequestSummary;
}
