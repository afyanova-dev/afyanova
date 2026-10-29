<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\TimesheetSummary;
use App\Domains\HR\Domain\AttendanceSessionReader;

final class GetTimesheetHandler
{
    public function __construct(
        private readonly AttendanceSessionReader $reader,
    ) {}

    public function __invoke(GetTimesheet $query): TimesheetSummary
    {
        return $this->reader->getTimesheet(
            staffId: $query->staffId,
            facilityId: $query->facilityId,
            startDate: $query->startDate,
            endDate: $query->endDate,
        );
    }
}
