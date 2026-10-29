<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * One quotation line (ADR-173 D-F). Carries competing supplier pricing — only
 * ever nested in {@see QuotationSummary}, returned on `procurement.quotations.view`
 * (§101). `lineTotal` = `unitPrice * offeredQty + tax − discount` (computed).
 */
final readonly class QuotationLineSummary
{
    public function __construct(
        public string $lineId,
        public int $lineNo,
        public string $rfqLineId,
        public string $unitPrice,
        public string $offeredQty,
        public ?string $taxAmount,
        public ?string $discountAmount,
        public string $lineTotal,
        public ?string $lineNote,
    ) {}
}
