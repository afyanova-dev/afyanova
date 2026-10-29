<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use App\Domains\Scheduling\Contracts\Dto\ScheduleSummary;
use App\Domains\Scheduling\Domain\ScheduleReader;

final class GetResourceScheduleHandler
{
    public function __construct(
        private readonly ScheduleReader $reader,
    ) {}

    /**
     * @return list<ScheduleSummary>
     */
    public function __invoke(GetResourceSchedule $query): array
    {
        return $this->reader->getForResource($query->resourceId);
    }
}
