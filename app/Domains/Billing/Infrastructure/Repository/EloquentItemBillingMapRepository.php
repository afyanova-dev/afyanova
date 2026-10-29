<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Repository;

use App\Domains\Billing\Domain\ItemBillingMapRepository;
use App\Domains\Billing\Domain\Model\ItemBillingMap;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see ItemBillingMapRepository} (IC §9; ADR-189 D-A).
 */
final class EloquentItemBillingMapRepository extends EloquentRepository implements ItemBillingMapRepository
{
    protected function model(): string
    {
        return ItemBillingMap::class;
    }

    public function getById(string $id): ItemBillingMap
    {
        return ItemBillingMap::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(ItemBillingMap::class, $id);
    }

    public function getByIdForUpdate(string $id): ItemBillingMap
    {
        return ItemBillingMap::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(ItemBillingMap::class, $id);
    }

    public function activeForItemForUpdate(string $invItemId): ?ItemBillingMap
    {
        return ItemBillingMap::query()
            ->where('inv_item_id', $invItemId)
            ->where('active', true)
            ->lockForUpdate()
            ->first();
    }

    public function activeForItem(string $invItemId): ?ItemBillingMap
    {
        return ItemBillingMap::query()
            ->where('inv_item_id', $invItemId)
            ->where('active', true)
            ->first();
    }
}
