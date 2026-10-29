<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\AttendanceEventSummary;
use App\Domains\HR\Domain\AttendanceEventReader;

final class GetAttendanceEventsHandler
{
    public function __construct(
        private readonly AttendanceEventReader $reader,
    ) {}

    /**
     * @return list<AttendanceEventSummary>
     */
    public function __invoke(GetAttendanceEvents $query): array
    {
        if ($query->staffId !== null) {
            return $this->reader->forStaff(
                staffId: $query->staffId,
                from: $query->from,
                to: $query->to,
                limit: $query->limit,
            );
        }

        if ($query->facilityId !== null) {
            return $this->reader->forFacility(
                facilityId: $query->facilityId,
                from: $query->from,
                to: $query->to,
                limit: $query->limit,
            );
        }

        return [];
    }
}
