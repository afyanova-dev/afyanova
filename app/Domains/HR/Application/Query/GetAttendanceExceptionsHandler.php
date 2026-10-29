<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\AttendanceSessionSummary;
use App\Domains\HR\Domain\AttendanceSessionReader;

final class GetAttendanceExceptionsHandler
{
    public function __construct(
        private readonly AttendanceSessionReader $reader,
    ) {}

    /**
     * @return list<AttendanceSessionSummary>
     */
    public function __invoke(GetAttendanceExceptions $query): array
    {
        return $this->reader->getExceptions(
            facilityId: $query->facilityId,
            from: $query->from,
            to: $query->to,
            staffId: $query->staffId,
        );
    }
}
