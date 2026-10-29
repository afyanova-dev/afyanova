<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A `Dispense` would push the cumulative dispensed quantity past the prescriber-
 * authorised quantity (16-pharmacy-domain.md §111 Invariant 5, §112, §113;
 * ADR-184 D-E — enforcing the ADR-183 C.10 `quantity_value` ceiling). No
 * override path in Pharm.3 (§110 deferred). Maps to HTTP 409.
 */
final class DispensingExceedsAuthorizedQuantity extends DomainException
{
    public static function forOrder(
        string $pharmacyOrderId,
        string $authorized,
        string $cumulative,
        string $requested,
    ): self {
        return new self(sprintf(
            'Dispensing [%s] of pharmacy order [%s] would exceed the authorised quantity [%s] (already dispensed [%s]; §111 Invariant 5 / §112 — ADR-184 D-E).',
            $requested,
            $pharmacyOrderId,
            $authorized,
            $cumulative,
        ));
    }

    public function errorCode(): string
    {
        return 'dispensing_exceeds_authorized_quantity';
    }
}
