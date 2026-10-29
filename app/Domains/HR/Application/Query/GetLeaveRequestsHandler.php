<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\LeaveRequestSummary;
use App\Domains\HR\Domain\LeaveRequestReader;

final class GetLeaveRequestsHandler
{
    public function __construct(
        private readonly LeaveRequestReader $reader,
    ) {}

    /**
     * @return list<LeaveRequestSummary>
     */
    public function __invoke(GetLeaveRequests $query): array
    {
        if ($query->staffId !== null) {
            return $this->reader->forStaff(
                staffId: $query->staffId,
                status: $query->status,
            );
        }

        if ($query->facilityId !== null) {
            return $this->reader->forFacility(
                facilityId: $query->facilityId,
                status: $query->status,
            );
        }

        return [];
    }
}
