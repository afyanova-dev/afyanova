<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An `AllocatePayment` would push Σ(active allocations) past the payment amount
 * or the invoice `net` (ADR-191 B5-A — the over-allocation guard, under a row
 * lock on both parents). Maps to HTTP 409.
 */
final class OverAllocation extends DomainException
{
    public static function payment(string $paymentId): self
    {
        return new self(sprintf('Allocation would exceed the amount of payment [%s].', $paymentId));
    }

    public static function invoice(string $invoiceId): self
    {
        return new self(sprintf('Allocation would exceed the net of invoice [%s].', $invoiceId));
    }

    public function errorCode(): string
    {
        return 'over_allocation';
    }
}
