<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `VoidInvoice` was called on an invoice that still has ≥ 1 active payment
 * allocation (ADR-191 G-5 — no container reaches a terminal state with active
 * dependents; the additive amendment-by-pointer to ADR-190). The clerk
 * `UnallocatePayment`s them first. Maps to HTTP 409.
 */
final class InvoiceHasActiveAllocations extends DomainException
{
    public static function id(string $invoiceId): self
    {
        return new self(sprintf(
            'Invoice [%s] cannot be voided while it has active payment allocations; unallocate them first.',
            $invoiceId,
        ));
    }

    public function errorCode(): string
    {
        return 'invoice_has_active_allocations';
    }
}
