<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `AllocatePayment` named a payment and an invoice that belong to different
 * Financial Accounts (ADR-191 B5-A). A payment settles obligations on its own
 * account. Maps to HTTP 409.
 */
final class AllocationAccountMismatch extends DomainException
{
    public static function of(string $paymentId, string $invoiceId): self
    {
        return new self(sprintf(
            'Payment [%s] and invoice [%s] are on different financial accounts.',
            $paymentId,
            $invoiceId,
        ));
    }

    public function errorCode(): string
    {
        return 'allocation_account_mismatch';
    }
}
