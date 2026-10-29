<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A stock movement would drive available (or physical on-hand) stock negative
 * (18-inventory-and-warehousing-domain.md §131, §307, Inv 5, Inv 29; ADR-170
 * D-R). There is no emergency override in Inv.1 (§132 deferred). Maps to
 * HTTP 409.
 */
final class InsufficientStock extends DomainException
{
    public static function forDecrement(string $itemId, string $locationId, string $availableQty, string $requestedQty): self
    {
        return new self(sprintf(
            'Insufficient stock for item [%s] at location [%s]: %s available, %s requested.',
            $itemId,
            $locationId,
            $availableQty,
            $requestedQty,
        ));
    }

    /** Inv 17 (ADR-180 D-D) — a reservation cannot exceed the currently reservable quantity. */
    public static function forReservation(string $itemId, string $locationId, string $availableQty, string $requestedQty): self
    {
        return new self(sprintf(
            'Cannot reserve item [%s] at location [%s]: %s available, %s requested.',
            $itemId,
            $locationId,
            $availableQty,
            $requestedQty,
        ));
    }

    public function errorCode(): string
    {
        return 'insufficient_stock';
    }
}
