<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Contracts\Dto\AttendanceSessionSummary;
use App\Domains\HR\Contracts\Dto\TimesheetSummary;
use Carbon\CarbonImmutable;

/**
 * Read-side port for AttendanceSession queries (25-hr-and-workforce-domain.md §6, §10).
 */
interface AttendanceSessionReader
{
    public function summary(string $sessionId): AttendanceSessionSummary;

    /**
     * @return list<AttendanceSessionSummary>
     */
    public function forStaff(
        string $staffId,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?string $status = null
    ): array;

    /**
     * @return list<AttendanceSessionSummary>
     */
    public function forFacility(
        string $facilityId,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?string $status = null
    ): array;

    /**
     * @return list<AttendanceSessionSummary>
     */
    public function getExceptions(
        string $facilityId,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?string $staffId = null
    ): array;

    public function getTimesheet(
        string $staffId,
        string $facilityId,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate
    ): TimesheetSummary;
}
