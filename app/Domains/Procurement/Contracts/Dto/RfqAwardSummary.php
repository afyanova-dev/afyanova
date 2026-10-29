<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * One award allocation row (ADR-173 D-F). The award OUTCOME — supplier, line,
 * qty, reason — IS returned on the coarse `procurement.access` gate (§101: a
 * requester may see who won; NOT any quotation price, winning or losing). The
 * awarded `unit_price` snapshot lives on `pcr_rfq_awards` (it seeds the PO) but
 * is deliberately NOT surfaced here — a price is visible only through
 * `GetRfqQuotations` / `CompareQuotations`, gated on `procurement.quotations.view`.
 */
final readonly class RfqAwardSummary
{
    public function __construct(
        public string $awardId,
        public string $rfqLineId,
        public string $quotationId,
        public string $supplierId,
        public string $awardedQty,
        public string $reason,
        public string $decidedByUserId,
        public string $decidedAt,
    ) {}
}
