<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Domain\Model\PaymentAllocation;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the payment-allocation aggregate (IC §9; ADR-191 B5-A).
 * The FIRST repository parameter of the allocation commands — the bus resolves
 * `PaymentPolicy` from it (allocation reuses the `billing.payments.manage` key).
 */
interface PaymentAllocationRepository extends Repository
{
    public function getByIdForUpdate(string $id): PaymentAllocation;

    /** The live allocation for a (payment, invoice) pair, `lockForUpdate()`, or null. */
    public function liveForPaymentAndInvoiceForUpdate(string $paymentId, string $invoiceId): ?PaymentAllocation;

    /**
     * Every live allocation of a payment, `lockForUpdate()` — the payment-reversal
     * cascade.
     *
     * @return list<PaymentAllocation>
     */
    public function liveForPaymentForUpdate(string $paymentId): array;

    /**
     * Σ(amount) of the payment's live allocations. Called with the payment row
     * already locked (`PaymentRepository::getByIdForUpdate`), so consistent.
     *
     * @return numeric-string scale-2
     */
    public function activeAmountForPayment(string $paymentId): string;

    /**
     * Σ(amount) of the invoice's live allocations. Called with the invoice row
     * already locked (`InvoiceRepository::getByIdForUpdate`), so consistent.
     *
     * @return numeric-string scale-2
     */
    public function activeAmountForInvoice(string $invoiceId): string;

    public function exists(string $id): bool;
}
