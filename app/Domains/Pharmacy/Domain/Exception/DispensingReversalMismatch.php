<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `ReverseDispensing` supplied a `pharmacyOrderId` that does not own the cited
 * Dispensing (ADR-185 D-D — the command carries both ids so the bus can
 * authorize against the order; the handler cross-checks). Maps to HTTP 409.
 */
final class DispensingReversalMismatch extends DomainException
{
    public static function orderDispensing(string $pharmacyOrderId, string $dispensingId, string $actualOrderId): self
    {
        return new self(sprintf(
            'Dispensing [%s] belongs to pharmacy order [%s], not [%s].',
            $dispensingId,
            $actualOrderId,
            $pharmacyOrderId,
        ));
    }

    public function errorCode(): string
    {
        return 'dispensing_reversal_mismatch';
    }
}
