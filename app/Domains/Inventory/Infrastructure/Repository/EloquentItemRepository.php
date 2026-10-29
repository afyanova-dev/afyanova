<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Repository;

use App\Domains\Inventory\Domain\ItemRepository;
use App\Domains\Inventory\Domain\Model\Item;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see ItemRepository} (IC §9; ADR-170 D-C).
 * `Item` emits no domain events (a catalog entry), so `save()` is inherited
 * unchanged.
 */
final class EloquentItemRepository extends EloquentRepository implements ItemRepository
{
    protected function model(): string
    {
        return Item::class;
    }

    public function getById(string $id): Item
    {
        return Item::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Item::class, $id);
    }

    public function getByIdForUpdate(string $id): Item
    {
        return Item::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Item::class, $id);
    }

    public function findActiveByCode(string $tenantId, string $code): ?Item
    {
        return Item::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->where('active', true)
            ->first();
    }
}
