<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Repository;

use App\Domains\Billing\Domain\AdjustmentRepository;
use App\Domains\Billing\Domain\Model\Adjustment;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

final class EloquentAdjustmentRepository extends EloquentRepository implements AdjustmentRepository
{
    protected function model(): string
    {
        return Adjustment::class;
    }

    public function getById(string $id): Adjustment
    {
        return Adjustment::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Adjustment::class, $id);
    }

    public function getByIdForUpdate(string $id): Adjustment
    {
        return Adjustment::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Adjustment::class, $id);
    }

    public function exists(string $id): bool
    {
        return Adjustment::query()->whereKey($id)->exists();
    }
}
