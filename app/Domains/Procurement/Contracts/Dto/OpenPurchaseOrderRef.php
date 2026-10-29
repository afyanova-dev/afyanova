<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * One open (awaiting-delivery) purchase order as a NARROW cross-domain
 * reference — the validated selection surface for PO-aware goods receipt
 * (frontend-foundation-inventory-operations.md Phase 7). Deliberately NOT
 * `PurchaseOrderSummary`: no supplier, price, notes or amendment history cross
 * the boundary. Only `lines` (the OPEN STOCK lines still awaiting delivery) are
 * carried, so a receiving surface can offer exactly the receivable set.
 */
final readonly class OpenPurchaseOrderRef
{
    /**
     * @param  list<OpenPurchaseOrderLineRef>  $lines
     */
    public function __construct(
        public string $purchaseOrderId,
        public string $poNumber,
        public string $facilityId,
        public string $status,
        public array $lines,
        public int $openLineCount = 0,
        public int $remainingLineCount = 0,
    ) {}
}
