<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RecordConsumption(reservationId)` cited a reservation whose item / location /
 * batch does not match the consumption, or whose `reserved_qty` is less than the
 * quantity being consumed (18-inventory-and-warehousing-domain.md §116;
 * ADR-180 D-F). Maps to HTTP 409.
 */
final class StockReservationMismatch extends DomainException
{
    public static function itemLocationBatch(string $reservationId): self
    {
        return new self(sprintf(
            'Reservation [%s] does not match this consumption\'s item / location / batch.',
            $reservationId,
        ));
    }

    public static function insufficientReserved(string $reservationId, string $reservedQty, string $requestedQty): self
    {
        return new self(sprintf(
            'Reservation [%s] holds [%s]; cannot fulfil a consumption of [%s].',
            $reservationId,
            $reservedQty,
            $requestedQty,
        ));
    }

    public function errorCode(): string
    {
        return 'stock_reservation_mismatch';
    }
}
