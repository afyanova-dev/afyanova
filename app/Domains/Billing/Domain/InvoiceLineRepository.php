<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Domain\Model\InvoiceLine;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the invoice-line aggregate (IC §9; ADR-190 D-B).
 * Manipulated only through the invoice command handlers (which resolve the
 * policy from `InvoiceRepository`).
 */
interface InvoiceLineRepository extends Repository
{
    public function getById(string $id): InvoiceLine;

    public function getByIdForUpdate(string $id): InvoiceLine;

    /** True if a LIVE line already claims this charge (INV-3). */
    public function liveLineExistsForCharge(string $chargeId): bool;

    /** The live line claiming this charge within one invoice, `lockForUpdate()`, or null. */
    public function liveLineForChargeOnInvoice(string $invoiceId, string $chargeId): ?InvoiceLine;

    /**
     * The live lines of one invoice, `lockForUpdate()`.
     *
     * @return list<InvoiceLine>
     */
    public function liveLinesForInvoiceForUpdate(string $invoiceId): array;

    /**
     * The charge ids currently claimed by a live line on any invoice for an
     * account's charges — the `CreateInvoice` / `GetUninvoicedCharges` exclusion
     * set.
     *
     * @param  list<string>  $chargeIds
     * @return list<string>
     */
    public function liveClaimedChargeIds(array $chargeIds): array;
}
