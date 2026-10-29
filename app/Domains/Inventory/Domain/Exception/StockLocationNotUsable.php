<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A stock movement referenced a retired stock location
 * (18-inventory-and-warehousing-domain.md §183, §184; ADR-170 D-D / D-L).
 * Maps to HTTP 409.
 */
final class StockLocationNotUsable extends DomainException
{
    public static function retired(string $locationId): self
    {
        return new self(sprintf('Stock location [%s] is retired and cannot receive stock movements (§183; ADR-170 D-L).', $locationId));
    }

    public function errorCode(): string
    {
        return 'stock_location_not_usable';
    }
}
