<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * One line of a stock count (Phase 8).
 *
 * `systemQty` is the snapshot taken when the count started; `countedQty` is
 * what the counter found, or NULL when the line has not been counted yet.
 * `variance` is `countedQty − systemQty`, or NULL while uncounted. The pack
 * size is a display help only.
 *
 * `isControlled` tells the sheet to ask for a witness when a controlled
 * medicine has a shortfall. `adjustmentId` / `adjustmentStatus` link a
 * difference to the correction raised at completion ("awaiting approval",
 * "approved", "rejected"), and `varianceSkippedReason` explains why a
 * difference was deliberately not raised automatically.
 */
final readonly class StockCountLineSummary
{
    public function __construct(
        public string $lineId,
        public string $itemId,
        public string $itemCode,
        public string $itemName,
        public string $uom,
        public ?string $packUnit,
        public ?string $packSize,
        public ?string $batchId,
        public ?string $batchNumber,
        public string $systemQty,
        public ?string $countedQty,
        public ?string $variance,
        public bool $isControlled = false,
        public ?string $adjustmentId = null,
        public ?string $adjustmentStatus = null,
        public ?string $varianceSkippedReason = null,
    ) {}
}
