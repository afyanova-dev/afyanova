<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Repository;

use App\Domains\Insurance\Domain\Model\Plan;
use App\Domains\Insurance\Domain\PlanRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see PlanRepository} (IC §9; ADR-198 D-H).
 */
final class EloquentPlanRepository extends EloquentRepository implements PlanRepository
{
    protected function model(): string
    {
        return Plan::class;
    }

    public function getById(string $id): Plan
    {
        return Plan::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Plan::class, $id);
    }

    public function getByIdForUpdate(string $id): Plan
    {
        return Plan::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Plan::class, $id);
    }

    public function existsByCode(string $payerId, string $code): bool
    {
        return Plan::query()->where('payer_id', $payerId)->where('code', $code)->exists();
    }
}
