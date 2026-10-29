<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use App\Domains\Procurement\Contracts\Dto\QuotationComparison;
use App\Domains\Procurement\Contracts\Dto\QuotationSummary;

/**
 * Read-side port for quotations (IC §6.2 / §6.3; ADR-173 D-F). **Every method
 * exposes competing supplier pricing** — the query handlers gate on
 * `procurement.quotations.view` (§101 / §208).
 */
interface QuotationReader
{
    /**
     * Every quotation on an RFQ, with lines + revision history.
     *
     * @return list<QuotationSummary>
     */
    public function listForRfq(string $rfqId): array;

    /** Per-RFQ-line price matrix across all quotations (ranking NOT applied — §35). */
    public function compareForRfq(string $rfqId): QuotationComparison;
}
