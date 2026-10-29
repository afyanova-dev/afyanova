<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Domain\Exception\InventoryCatalogConflict;
use App\Domains\Inventory\Domain\Exception\InventoryCatalogInUse;
use App\Domains\Inventory\Domain\ItemRepository;
use App\Domains\Inventory\Domain\StockBalanceRepository;

final class SetInventoryItemActiveHandler
{
    public function __construct(
        private readonly ItemRepository $items,
        private readonly StockBalanceRepository $balances,
    ) {}

    public function __invoke(SetInventoryItemActive $command): void
    {
        $item = $this->items->getByIdForUpdate($command->itemId);
        if (! $command->active && $this->balances->hasNonZeroBalanceForItem($command->itemId)) {
            throw InventoryCatalogInUse::itemHasStock($command->itemId);
        }
        if ($command->active && ! (bool) $item->active) {
            $existing = $this->items->findActiveByCode((string) $item->tenant_id, (string) $item->code);
            if ($existing !== null && (string) $existing->id !== $command->itemId) {
                throw InventoryCatalogConflict::itemCode((string) $item->tenant_id, (string) $item->code);
            }
        }

        if ((bool) $item->active === $command->active) {
            return;
        }

        $item->revise(active: $command->active);
        $this->items->save($item);
    }
}
