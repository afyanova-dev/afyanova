<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Exception;

use App\Shared\Domain\DomainException;

final class InventoryCatalogInUse extends DomainException
{
    public static function itemHasStock(string $itemId): self
    {
        return new self(sprintf('Inventory item [%s] cannot be deactivated while it has on-hand or reserved stock.', $itemId));
    }

    public static function locationHasStock(string $locationId): self
    {
        return new self(sprintf('Stock location [%s] cannot be deactivated while it has on-hand or reserved stock.', $locationId));
    }

    public function errorCode(): string
    {
        return 'inventory_catalog_in_use';
    }
}
