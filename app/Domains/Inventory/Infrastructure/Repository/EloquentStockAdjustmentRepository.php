<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Repository;

use App\Domains\Inventory\Domain\Model\StockAdjustment;
use App\Domains\Inventory\Domain\StockAdjustmentRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see StockAdjustmentRepository} (IC §9;
 * ADR-170 D-H). `StockAdjustment` emits no events (the balance emits
 * `StockAdjusted` on approval), so `save()` is inherited unchanged.
 */
final class EloquentStockAdjustmentRepository extends EloquentRepository implements StockAdjustmentRepository
{
    protected function model(): string
    {
        return StockAdjustment::class;
    }

    public function getById(string $id): StockAdjustment
    {
        return StockAdjustment::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(StockAdjustment::class, $id);
    }

    public function getByIdForUpdate(string $id): StockAdjustment
    {
        return StockAdjustment::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(StockAdjustment::class, $id);
    }
}
