<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\WorkforceAvailabilitySummary;
use App\Domains\HR\Domain\WorkforceAvailabilityReader;

final class GetWorkforceAvailabilityHandler
{
    public function __construct(
        private readonly WorkforceAvailabilityReader $reader,
    ) {}

    /**
     * @return list<WorkforceAvailabilitySummary>
     */
    public function __invoke(GetWorkforceAvailability $query): array
    {
        return $this->reader->getAvailability(
            facilityId: $query->facilityId,
            atTime: $query->atTime,
            departmentId: $query->departmentId,
            unitId: $query->unitId,
            cadreId: $query->cadreId,
        );
    }
}
