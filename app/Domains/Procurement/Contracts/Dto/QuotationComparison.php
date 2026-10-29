<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * Per-RFQ-line comparison of every SUBMITTED / RECEIVED quotation (ADR-173
 * D-F). **Carries competing supplier pricing** — returned ONLY through
 * `CompareQuotations`, gated on `procurement.quotations.view` (§101 / §208).
 * The ranking is NOT applied — §35 ("lowest price is not always the winner");
 * the reader returns the raw matrix, the human decides.
 *
 * @param  list<QuotationComparisonRow>  $rows
 * @param  list<array{quotationId: string, supplierId: string, quotationNumber: string, currency: string, leadTimeDays: int|null, quotationTotal: string}>  $quotations
 */
final readonly class QuotationComparison
{
    /**
     * @param  list<QuotationComparisonRow>  $rows
     * @param  list<array{quotationId: string, supplierId: string, quotationNumber: string, currency: string, leadTimeDays: int|null, quotationTotal: string}>  $quotations
     */
    public function __construct(
        public string $rfqId,
        public array $quotations,
        public array $rows,
    ) {}
}
