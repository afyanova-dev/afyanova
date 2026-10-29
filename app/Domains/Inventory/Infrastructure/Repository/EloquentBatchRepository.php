<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Repository;

use App\Domains\Inventory\Domain\BatchRepository;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see BatchRepository} (IC §9; ADR-170 D-E).
 */
final class EloquentBatchRepository extends EloquentRepository implements BatchRepository
{
    protected function model(): string
    {
        return Batch::class;
    }

    public function getById(string $id): Batch
    {
        return Batch::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Batch::class, $id);
    }

    public function getByIdForUpdate(string $id): Batch
    {
        return Batch::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Batch::class, $id);
    }

    public function findByItemAndNumber(string $tenantId, string $itemId, string $batchNumber): ?Batch
    {
        return Batch::query()
            ->where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where('batch_number', $batchNumber)
            ->first();
    }
}
