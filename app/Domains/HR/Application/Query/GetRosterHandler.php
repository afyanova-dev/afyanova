<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\RosterEntrySummary;
use App\Domains\HR\Domain\RosterEntryReader;

final class GetRosterHandler
{
    public function __construct(private readonly RosterEntryReader $reader) {}

    /**
     * @return list<RosterEntrySummary>
     */
    public function __invoke(GetRoster $query): array
    {
        return $this->reader->forFacilityAndPeriod(
            facilityId: $query->facilityId,
            startDate: $query->startDate,
            endDate: $query->endDate,
            departmentId: $query->departmentId,
            unitId: $query->unitId,
        );
    }
}
