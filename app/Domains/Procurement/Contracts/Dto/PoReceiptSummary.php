<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * One matched goods-receipt against a purchase order (ADR-172 D-K). Nested in
 * {@see PurchaseOrderSummary}. Movement / batch / location refs are OPAQUE
 * Inventory identifiers. `poLineId` is null when the received item is not on any
 * line of the PO (recorded for provenance).
 */
final readonly class PoReceiptSummary
{
    public function __construct(
        public string $receiptId,
        public ?string $poLineId,
        public string $sourceEventId,
        public string $movementNumber,
        public string $itemRef,
        public ?string $batchRef,
        public string $quantity,
        public string $uom,
        public string $sourceType,
        public string $receivedAt,
    ) {}
}
