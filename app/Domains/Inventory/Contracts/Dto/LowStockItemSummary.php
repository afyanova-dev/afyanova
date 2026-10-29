<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * One item on the facility's low-stock worklist
 * (frontend-foundation-inventory-operations.md Phase 8).
 *
 * `minLevel`, `onHandQty` and `shortfall` are all BASE units (CAPSULE, not
 * BOX), the same unit stock is counted in. `onHandQty` counts USABLE stock
 * only — expired batches are excluded by the same `Batch::isExpired()` rule the
 * dispense gate uses, and their quantity is reported separately in
 * `expiredQty` so a seemingly stocked item is never unexplained.
 *
 * `shortfall` is what to buy to get back to the minimum; `outOfStock` means
 * there is no usable stock at all. Read-only — this DTO never moves stock.
 */
final readonly class LowStockItemSummary
{
    public function __construct(
        public string $itemId,
        public string $itemCode,
        public string $itemName,
        public string $uom,
        public ?string $packUnit,
        public ?string $packSize,
        public string $minLevel,
        public string $onHandQty,
        public string $expiredQty,
        public string $shortfall,
        public bool $outOfStock,
    ) {}
}
