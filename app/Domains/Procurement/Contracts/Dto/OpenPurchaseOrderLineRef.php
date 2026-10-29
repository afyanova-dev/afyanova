<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * One OPEN, STOCK purchase-order line as a NARROW cross-domain reference — the
 * minimum a receiving surface needs to offer a line for goods receipt
 * (frontend-foundation-inventory-operations.md Phase 7). Deliberately NOT
 * `PoLineSummary`: it carries no price, contract linkage or delivery metadata —
 * only what and how much is still expected. Quantities are in the item's BASE
 * unit (`uom`): `remainingQty` is `orderedQty − receivedQty` clamped at 0.
 * `enteredUnit` / `enteredQty` / `packSize` are the display-only record of how
 * the line was ordered ("3 BOX of 100") — receiving always posts base units.
 */
final readonly class OpenPurchaseOrderLineRef
{
    public function __construct(
        public string $lineId,
        public int $lineNo,
        public string $itemRef,
        public ?string $itemCode,
        public ?string $itemName,
        public string $orderedQty,
        public string $receivedQty,
        public string $remainingQty,
        public string $uom,
        public ?string $enteredUnit,
        public ?string $enteredQty,
        public ?string $packSize,
    ) {}
}
