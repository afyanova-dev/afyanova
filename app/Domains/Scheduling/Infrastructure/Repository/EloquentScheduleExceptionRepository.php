<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Infrastructure\Repository;

use App\Domains\Scheduling\Domain\Exception\ScheduleExceptionNotFoundException;
use App\Domains\Scheduling\Domain\Model\ScheduleException;
use App\Domains\Scheduling\Domain\ScheduleExceptionRepository;
use App\Shared\Domain\EloquentRepository;
use Carbon\CarbonImmutable;

final class EloquentScheduleExceptionRepository extends EloquentRepository implements ScheduleExceptionRepository
{
    protected function model(): string
    {
        return ScheduleException::class;
    }

    public function getById(string $id): ScheduleException
    {
        return ScheduleException::query()->whereKey($id)->first()
            ?? throw ScheduleExceptionNotFoundException::forId($id);
    }

    public function findForDate(string $resourceId, CarbonImmutable $date): ?ScheduleException
    {
        return ScheduleException::query()
            ->where('resource_id', $resourceId)
            ->whereDate('exception_date', $date->toDateString())
            ->first();
    }
}
