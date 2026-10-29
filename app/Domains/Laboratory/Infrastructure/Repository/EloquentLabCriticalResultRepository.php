<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Repository;

use App\Domains\Laboratory\Domain\LabCriticalResultRepository;
use App\Domains\Laboratory\Domain\Model\LabCriticalResult;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of LabCriticalResultRepository (ADR-161 D-D).
 */
final class EloquentLabCriticalResultRepository extends EloquentRepository implements LabCriticalResultRepository
{
    protected function model(): string
    {
        return LabCriticalResult::class;
    }

    public function getById(string $id): LabCriticalResult
    {
        return LabCriticalResult::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(LabCriticalResult::class, $id);
    }

    public function getByIdForUpdate(string $id): LabCriticalResult
    {
        return LabCriticalResult::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(LabCriticalResult::class, $id);
    }
}
