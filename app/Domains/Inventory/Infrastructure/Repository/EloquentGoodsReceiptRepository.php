<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Repository;

use App\Domains\Inventory\Domain\GoodsReceiptRepository;
use App\Domains\Inventory\Domain\Model\GoodsReceipt;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see GoodsReceiptRepository} (Phase 1).
 */
final class EloquentGoodsReceiptRepository extends EloquentRepository implements GoodsReceiptRepository
{
    protected function model(): string
    {
        return GoodsReceipt::class;
    }

    public function getById(string $id): GoodsReceipt
    {
        return GoodsReceipt::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(GoodsReceipt::class, $id);
    }

    public function getByIdForUpdate(string $id): GoodsReceipt
    {
        return GoodsReceipt::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(GoodsReceipt::class, $id);
    }
}
