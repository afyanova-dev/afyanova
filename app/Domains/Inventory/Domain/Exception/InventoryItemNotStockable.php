<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A stock movement referenced an item that is retired, or supplied batch
 * details inconsistent with the item's tracking rules
 * (18-inventory-and-warehousing-domain.md §8, §42, §43, §181; ADR-170 D-C /
 * D-L). Maps to HTTP 409.
 */
final class InventoryItemNotStockable extends DomainException
{
    public static function retired(string $itemId): self
    {
        return new self(sprintf('Inventory item [%s] is retired and cannot receive stock movements (§181; ADR-170 D-L).', $itemId));
    }

    public static function batchNumberRequired(string $itemId): self
    {
        return new self(sprintf('Inventory item [%s] is batch-tracked — a batch number is required (§39; ADR-170 D-L).', $itemId));
    }

    public static function expiryRequired(string $itemId): self
    {
        return new self(sprintf('Inventory item [%s] is expiry-tracked — a batch expiry date is required (§43 / Inv 28; ADR-170 D-L).', $itemId));
    }

    public static function serialised(string $itemId): self
    {
        return new self(sprintf('Inventory item [%s] is serial-tracked; serial-number receiving is not available in this workflow yet.', $itemId));
    }

    public function errorCode(): string
    {
        return 'inventory_item_not_stockable';
    }
}
