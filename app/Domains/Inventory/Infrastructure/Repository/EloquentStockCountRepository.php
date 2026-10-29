<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Repository;

use App\Domains\Inventory\Domain\Model\StockCount;
use App\Domains\Inventory\Domain\StockCountRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see StockCountRepository} (Phase 8, stock
 * counts). `StockCount` emits no events, so `save()` is inherited unchanged.
 */
final class EloquentStockCountRepository extends EloquentRepository implements StockCountRepository
{
    protected function model(): string
    {
        return StockCount::class;
    }

    public function getById(string $id): StockCount
    {
        return StockCount::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(StockCount::class, $id);
    }

    public function getByIdForUpdate(string $id): StockCount
    {
        return StockCount::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(StockCount::class, $id);
    }
}
