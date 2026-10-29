<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Contracts\Dto\PaymentAllocationSummary;

/**
 * Read-side port for payment allocations (IC §6.2; ADR-191 B5-A / B5-D). Feeds
 * `PaymentReader::unallocatedAmount` and `InvoiceReader::paidAmount /
 * paymentStatus`. Returns DTOs / scalars only.
 */
interface PaymentAllocationReader
{
    /** @return numeric-string  Σ(active allocation amounts) for a payment, scale-2 */
    public function activeAmountForPayment(string $paymentId): string;

    /** @return numeric-string  Σ(active allocation amounts) for an invoice, scale-2 */
    public function activeAmountForInvoice(string $invoiceId): string;

    /** True if the invoice has ≥ 1 active allocation (the `VoidInvoice` guard — ADR-191 G-5). */
    public function invoiceHasActiveAllocation(string $invoiceId): bool;

    /**
     * The allocations of a payment (active + historical), newest first.
     *
     * @return list<PaymentAllocationSummary>
     */
    public function forPayment(string $paymentId): array;
}
