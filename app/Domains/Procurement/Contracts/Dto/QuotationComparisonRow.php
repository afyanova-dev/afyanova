<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * One RFQ line across all quotations (ADR-173 D-F). `cells` is keyed by
 * `quotationId`; a quotation that did not quote this line has no entry.
 *
 * @param  array<string, array{unitPrice: string, offeredQty: string, lineTotal: string}>  $cells
 */
final readonly class QuotationComparisonRow
{
    /**
     * @param  array<string, array{unitPrice: string, offeredQty: string, lineTotal: string}>  $cells
     */
    public function __construct(
        public string $rfqLineId,
        public int $lineNo,
        public ?string $itemCode,
        public ?string $itemName,
        public ?string $description,
        public string $requestedQty,
        public string $uom,
        public array $cells,
    ) {}
}
