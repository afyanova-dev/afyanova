<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use App\Domains\Scheduling\Contracts\Dto\SlotSummary;
use App\Domains\Scheduling\Domain\SlotReader;

final class GetAvailableSlotsHandler
{
    public function __construct(
        private readonly SlotReader $reader,
    ) {}

    /**
     * @return list<SlotSummary>
     */
    public function __invoke(GetAvailableSlots $query): array
    {
        return $this->reader->getAvailableSlots(
            $query->facilityId,
            $query->resourceId,
            $query->appointmentTypeId,
            $query->startDate,
            $query->endDate,
        );
    }
}
