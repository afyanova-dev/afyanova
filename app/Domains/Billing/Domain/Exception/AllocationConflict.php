<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A concurrent `AllocatePayment` already created the live (payment, invoice)
 * allocation between this handler's pre-check and its insert — the partial
 * unique index `bill_payment_allocations_one_live_per_pair` rejected it
 * (ADR-191 B5-A). The caller re-reads and retries. Maps to HTTP 409.
 */
final class AllocationConflict extends DomainException
{
    public static function of(string $paymentId, string $invoiceId): self
    {
        return new self(sprintf(
            'Payment [%s] already has a live allocation to invoice [%s]; unallocate first or retry.',
            $paymentId,
            $invoiceId,
        ));
    }

    public function errorCode(): string
    {
        return 'allocation_conflict';
    }
}
