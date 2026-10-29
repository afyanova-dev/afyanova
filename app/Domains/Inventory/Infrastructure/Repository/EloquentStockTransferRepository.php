<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Repository;

use App\Domains\Inventory\Domain\Model\StockTransfer;
use App\Domains\Inventory\Domain\StockTransferRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see StockTransferRepository} (Phase 8).
 *
 * `StockTransfer` emits no events (the balances emit `StockTransferred`), so
 * `save()` is inherited unchanged.
 */
final class EloquentStockTransferRepository extends EloquentRepository implements StockTransferRepository
{
    protected function model(): string
    {
        return StockTransfer::class;
    }

    public function getById(string $id): StockTransfer
    {
        return StockTransfer::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(StockTransfer::class, $id);
    }

    public function getByIdForUpdate(string $id): StockTransfer
    {
        return StockTransfer::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(StockTransfer::class, $id);
    }
}
