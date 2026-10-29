<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\LeaveCalendarEntry;
use App\Domains\HR\Domain\LeaveRequestReader;

final class GetLeaveCalendarHandler
{
    public function __construct(
        private readonly LeaveRequestReader $reader,
    ) {}

    /**
     * @return list<LeaveCalendarEntry>
     */
    public function __invoke(GetLeaveCalendar $query): array
    {
        return $this->reader->getCalendar(
            facilityId: $query->facilityId,
            startDate: $query->startDate,
            endDate: $query->endDate,
        );
    }
}
