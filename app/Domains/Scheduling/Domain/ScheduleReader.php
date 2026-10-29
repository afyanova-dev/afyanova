<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain;

use App\Domains\Scheduling\Contracts\Dto\ScheduleSummary;

/**
 * Read-side reader port for Schedule.
 */
interface ScheduleReader
{
    /**
     * @return list<ScheduleSummary>
     */
    public function getForResource(string $resourceId): array;
}
