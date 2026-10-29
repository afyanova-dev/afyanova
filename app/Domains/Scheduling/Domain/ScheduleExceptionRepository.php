<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain;

use App\Domains\Scheduling\Domain\Model\ScheduleException;
use App\Shared\Domain\Repository;
use Carbon\CarbonImmutable;

/**
 * Write-side repository port for ScheduleException aggregate root.
 */
interface ScheduleExceptionRepository extends Repository
{
    public function getById(string $id): ScheduleException;

    public function findForDate(string $resourceId, CarbonImmutable $date): ?ScheduleException;
}
