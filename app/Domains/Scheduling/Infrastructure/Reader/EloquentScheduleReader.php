<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Infrastructure\Reader;

use App\Domains\Scheduling\Contracts\Dto\ScheduleSummary;
use App\Domains\Scheduling\Domain\Model\Schedule;
use App\Domains\Scheduling\Domain\ScheduleReader;

final class EloquentScheduleReader implements ScheduleReader
{
    public function getForResource(string $resourceId): array
    {
        return array_values(Schedule::query()
            ->where('resource_id', $resourceId)
            ->where('is_active', true)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get()
            ->map(fn (Schedule $s): ScheduleSummary => new ScheduleSummary(
                id: (string) $s->id,
                facilityId: (string) $s->facility_id,
                resourceId: (string) $s->resource_id,
                appointmentTypeId: $s->appointment_type_id !== null ? (string) $s->appointment_type_id : null,
                dayOfWeek: (int) $s->day_of_week,
                startTime: (string) $s->start_time,
                endTime: (string) $s->end_time,
                effectiveFrom: $s->effective_from->toDateString(),
                effectiveTo: $s->effective_to?->toDateString(),
                isActive: (bool) $s->is_active,
            ))
            ->all());
    }
}
