<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Infrastructure\Repository;

use App\Domains\Radiology\Domain\Model\RadiologyCriticalFinding;
use App\Domains\Radiology\Domain\RadiologyCriticalFindingRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of RadiologyCriticalFindingRepository (ADR-169 D-B).
 */
final class EloquentRadiologyCriticalFindingRepository extends EloquentRepository implements RadiologyCriticalFindingRepository
{
    protected function model(): string
    {
        return RadiologyCriticalFinding::class;
    }

    public function getById(string $id): RadiologyCriticalFinding
    {
        return RadiologyCriticalFinding::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(RadiologyCriticalFinding::class, $id);
    }

    public function getByIdForUpdate(string $id): RadiologyCriticalFinding
    {
        return RadiologyCriticalFinding::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(RadiologyCriticalFinding::class, $id);
    }
}
