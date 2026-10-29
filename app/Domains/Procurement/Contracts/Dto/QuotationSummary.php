<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * One supplier quotation + its lines + its revision history (ADR-173 D-F).
 * **Carries competing supplier pricing** — returned ONLY through
 * `GetRfqQuotations`, gated on `procurement.quotations.view` (§101 / §208).
 *
 * @param  list<QuotationLineSummary>  $lines
 * @param  list<QuotationRevisionSummary>  $revisions
 */
final readonly class QuotationSummary
{
    /**
     * @param  list<QuotationLineSummary>  $lines
     * @param  list<QuotationRevisionSummary>  $revisions
     */
    public function __construct(
        public string $quotationId,
        public string $facilityId,
        public string $quotationNumber,
        public string $rfqId,
        public string $supplierId,
        public ?string $supplierQuoteRef,
        public string $currency,
        public ?string $validityDate,
        public ?int $leadTimeDays,
        public ?string $paymentTerms,
        public ?string $deliveryTerms,
        public ?string $notes,
        public string $status,
        public int $revisionCount,
        public string $receivedByUserId,
        public string $quotationTotal,
        public array $lines,
        public array $revisions,
        public ?string $createdAt,
    ) {}
}
