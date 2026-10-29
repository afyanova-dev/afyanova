<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A pickup command supplied a `pharmacyOrderId` that does not own the cited
 * `dispensingId`'s Pickup (ADR-186 D-D — the command carries both ids so the bus
 * can authorize against the order; the handler cross-checks). Maps to HTTP 409.
 */
final class PickupMismatch extends DomainException
{
    public static function orderPickup(string $pharmacyOrderId, string $dispensingId, string $actualOrderId): self
    {
        return new self(sprintf(
            'The pickup for dispensing [%s] belongs to pharmacy order [%s], not [%s].',
            $dispensingId,
            $actualOrderId,
            $pharmacyOrderId,
        ));
    }

    public function errorCode(): string
    {
        return 'pickup_mismatch';
    }
}
