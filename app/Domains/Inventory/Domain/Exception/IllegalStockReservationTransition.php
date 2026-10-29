<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A stock-reservation command was dispatched against a reservation in a status
 * that does not permit it (18-inventory-and-warehousing-domain.md §113;
 * ADR-180 D-C / D-F). Maps to HTTP 409.
 */
final class IllegalStockReservationTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf('%s is not legal from stock-reservation status [%s].', $action, $currentStatus));
    }

    public function errorCode(): string
    {
        return 'illegal_stock_reservation_transition';
    }
}
