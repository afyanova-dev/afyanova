<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Contracts\Dto\ChargeSummary;
use App\Domains\Billing\Contracts\Dto\InvoiceSummary;

/**
 * Read-side port for invoices + the "ready to invoice" worklist (IC §6.2;
 * ADR-190 D-D). Returns DTOs only.
 */
interface InvoiceReader
{
    /** Throws `App\Domains\Billing\Domain\Exception\InvoiceNotFound` if absent (RLS). */
    public function summary(string $invoiceId): InvoiceSummary;

    /**
     * @return list<InvoiceSummary> newest first
     */
    public function forAccount(string $financialAccountId): array;

    /**
     * POSTED charges for an account that are not reversed and not claimed by a
     * live invoice line — the eligible-to-invoice set (reuses Bill.3's
     * `ChargeSummary`).
     *
     * @return list<ChargeSummary>
     */
    public function uninvoicedCharges(string $financialAccountId): array;

    /**
     * Handover clearance for a dispensing's posted charge. This boolean-only
     * operational read must fail closed for missing, uninvoiced, unpaid, or
     * unconfirmed-coverage charges.
     */
    public function dispensingClearedForHandover(string $dispensingId): bool;
}
