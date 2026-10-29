<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Infrastructure\Reader;

use App\Domains\Scheduling\Contracts\Dto\ScheduleExceptionSummary;
use App\Domains\Scheduling\Domain\Model\ScheduleException;
use App\Domains\Scheduling\Domain\ScheduleExceptionReader;
use Carbon\CarbonImmutable;

final class EloquentScheduleExceptionReader implements ScheduleExceptionReader
{
    public function getForResource(string $resourceId, CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        return array_values(ScheduleException::query()
            ->where('resource_id', $resourceId)
            ->whereBetween('exception_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->orderBy('exception_date')
            ->get()
            ->map(fn (ScheduleException $e): ScheduleExceptionSummary => new ScheduleExceptionSummary(
                id: (string) $e->id,
                facilityId: (string) $e->facility_id,
                resourceId: (string) $e->resource_id,
                exceptionDate: $e->exception_date->toDateString(),
                startTime: $e->start_time !== null ? (string) $e->start_time : null,
                endTime: $e->end_time !== null ? (string) $e->end_time : null,
                isAvailable: (bool) $e->is_available,
                reason: (string) $e->reason,
            ))
            ->all());
    }
}
