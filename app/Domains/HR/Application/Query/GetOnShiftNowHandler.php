<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\OnShiftStaffSummary;
use App\Domains\HR\Domain\RosterEntryReader;

final class GetOnShiftNowHandler
{
    public function __construct(private readonly RosterEntryReader $reader) {}

    /**
     * @return list<OnShiftStaffSummary>
     */
    public function __invoke(GetOnShiftNow $query): array
    {
        return $this->reader->getOnShiftNow(
            facilityId: $query->facilityId,
            unitId: $query->unitId,
            atTime: $query->atTime,
        );
    }
}
