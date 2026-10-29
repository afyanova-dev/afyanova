<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\AttendanceSessionSummary;
use App\Domains\HR\Domain\AttendanceSessionReader;

final class GetAttendanceSessionsHandler
{
    public function __construct(
        private readonly AttendanceSessionReader $reader,
    ) {}

    /**
     * @return list<AttendanceSessionSummary>
     */
    public function __invoke(GetAttendanceSessions $query): array
    {
        if ($query->staffId !== null) {
            return $this->reader->forStaff(
                staffId: $query->staffId,
                from: $query->from,
                to: $query->to,
                status: $query->status,
            );
        }

        if ($query->facilityId !== null) {
            return $this->reader->forFacility(
                facilityId: $query->facilityId,
                from: $query->from,
                to: $query->to,
                status: $query->status,
            );
        }

        return [];
    }
}
