<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain;

use App\Domains\Scheduling\Contracts\Dto\ScheduleExceptionSummary;
use Carbon\CarbonImmutable;

/**
 * Read-side reader port for ScheduleException.
 */
interface ScheduleExceptionReader
{
    /**
     * @return list<ScheduleExceptionSummary>
     */
    public function getForResource(string $resourceId, CarbonImmutable $startDate, CarbonImmutable $endDate): array;
}
