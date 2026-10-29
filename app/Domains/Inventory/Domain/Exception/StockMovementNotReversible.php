<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `ReverseConsumption` cited a movement that cannot be reversed
 * (18-inventory-and-warehousing-domain.md §84; ADR-185 D-C): it is not a
 * `CONSUMPTION` OUT, or it has already been reversed. Maps to HTTP 409.
 */
final class StockMovementNotReversible extends DomainException
{
    public static function notConsumption(string $movementId, string $movementType): self
    {
        return new self(sprintf(
            'Stock movement [%s] is a [%s]; only a CONSUMPTION movement can be reversed.',
            $movementId,
            $movementType,
        ));
    }

    public static function alreadyReversed(string $movementId): self
    {
        return new self(sprintf(
            'Stock movement [%s] has already been reversed.',
            $movementId,
        ));
    }

    public function errorCode(): string
    {
        return 'stock_movement_not_reversible';
    }
}
