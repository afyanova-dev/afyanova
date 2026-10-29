<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use App\Domains\Scheduling\Contracts\Dto\WaitlistEntrySummary;
use App\Domains\Scheduling\Domain\WaitlistEntryReader;

final readonly class GetFacilityWaitlistHandler
{
    public function __construct(
        private WaitlistEntryReader $reader,
    ) {}

    /**
     * @return list<WaitlistEntrySummary>
     */
    public function __invoke(GetFacilityWaitlist $query): array
    {
        return $this->reader->getFacilityWaitlist(
            facilityId: $query->facilityId,
            status: $query->status,
            appointmentTypeId: $query->appointmentTypeId,
            resourceId: $query->resourceId,
        );
    }
}
