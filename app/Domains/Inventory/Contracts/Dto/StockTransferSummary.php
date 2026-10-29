<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * One stock transfer document for the Transfers screen (Phase 8).
 *
 * `quantity` is in the item's BASE unit. Both location codes are included so
 * the row reads as "main store → pharmacy counter". NOT an event payload.
 */
final readonly class StockTransferSummary
{
    public function __construct(
        public string $transferId,
        public string $transferNumber,
        public string $itemId,
        public string $itemCode,
        public string $itemName,
        public string $fromLocationId,
        public string $fromLocationCode,
        public string $toLocationId,
        public string $toLocationCode,
        public ?string $batchId,
        public ?string $batchNumber,
        public string $quantity,
        public string $uom,
        public ?string $reasonText,
        public string $movedByUserId,
        public string $movedAt,
    ) {}
}
