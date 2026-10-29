<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Infrastructure\Repository;

use App\Domains\Scheduling\Domain\Exception\ScheduleNotFoundException;
use App\Domains\Scheduling\Domain\Model\Schedule;
use App\Domains\Scheduling\Domain\ScheduleRepository;
use App\Shared\Domain\EloquentRepository;

final class EloquentScheduleRepository extends EloquentRepository implements ScheduleRepository
{
    protected function model(): string
    {
        return Schedule::class;
    }

    public function getById(string $id): Schedule
    {
        return Schedule::query()->whereKey($id)->first()
            ?? throw ScheduleNotFoundException::forId($id);
    }

    public function getActiveForResource(string $resourceId, int $dayOfWeek): array
    {
        /** @var list<Schedule> */
        return Schedule::query()
            ->where('resource_id', $resourceId)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->get()
            ->all();
    }
}
