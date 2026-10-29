<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Domain\Exception\InventoryCatalogConflict;
use App\Domains\Inventory\Domain\ItemRepository;
use App\Domains\Inventory\Domain\Model\Item;
use App\Shared\Tenancy\TenantContext;

final class DefineInventoryItemHandler
{
    public function __construct(
        private readonly ItemRepository $items,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(DefineInventoryItem $command): void
    {
        $tenantId = $this->context->tenantId();
        $code = strtoupper(trim($command->code));

        if ($this->items->findActiveByCode($tenantId, $code) !== null) {
            throw InventoryCatalogConflict::itemCode($tenantId, $code);
        }

        $item = Item::define(
            id: $command->itemId,
            tenantId: $tenantId,
            code: $command->code,
            name: $command->name,
            itemType: $command->itemType,
            uom: $command->uom,
            description: $command->description,
            category: $command->category,
            trackBatch: $command->trackBatch,
            trackSerial: $command->trackSerial,
            trackExpiry: $command->trackExpiry,
            isControlled: $command->isControlled,
            packUnit: $command->packUnit,
            packSize: $command->packSize,
        );

        $this->items->save($item);
    }
}
