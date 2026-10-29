<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain;

use App\Domains\Scheduling\Domain\Model\Schedule;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for Schedule aggregate root.
 */
interface ScheduleRepository extends Repository
{
    public function getById(string $id): Schedule;

    /**
     * @return list<Schedule>
     */
    public function getActiveForResource(string $resourceId, int $dayOfWeek): array;
}
