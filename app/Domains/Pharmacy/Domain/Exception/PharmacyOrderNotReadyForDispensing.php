<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `Dispense` was dispatched against a Pharmacy Order that is not VALIDATED or
 * PARTIALLY_DISPENSED (16-pharmacy-domain.md §122; ADR-184 D-C). A fully
 * DISPENSED / REJECTED / CANCELLED / ON_HOLD / RECEIVED order cannot be
 * dispensed. Maps to HTTP 409.
 */
final class PharmacyOrderNotReadyForDispensing extends DomainException
{
    public static function status(string $pharmacyOrderId, string $status): self
    {
        return new self(sprintf(
            'Pharmacy order [%s] is [%s]; dispensing requires VALIDATED or PARTIALLY_DISPENSED.',
            $pharmacyOrderId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'pharmacy_order_not_ready_for_dispensing';
    }
}
