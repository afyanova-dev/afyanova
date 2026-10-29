<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A reclassification of an item that already has stock history was attempted
 * (`inventory-item-master-spec.md` §5). `item_type` gates what Pharmacy may
 * dispense and `uom` is the unit balances are interpreted in — both are
 * one-way doors once movements exist. Maps to HTTP 409.
 */
final class InventoryItemReclassificationNotAllowed extends DomainException
{
    public static function forItem(string $itemId): self
    {
        return new self(sprintf(
            'Inventory item [%s] has stock history — its type and base unit cannot be changed.',
            $itemId,
        ));
    }

    public function errorCode(): string
    {
        return 'inventory_item_reclassification_not_allowed';
    }
}
