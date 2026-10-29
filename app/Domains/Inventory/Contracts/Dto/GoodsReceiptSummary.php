<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * A goods-receipt register row (Phase 1 / P1-08).
 */
final readonly class GoodsReceiptSummary
{
    public function __construct(
        public string $goodsReceiptId,
        public string $facilityId,
        public string $receiptNumber,
        public string $receivedAt,
        public string $sourceType,
        public ?string $poRef,
        public ?string $supplierRef,
        public string $locationId,
        public string $locationCode,
        public string $locationName,
        public ?string $deliveryNoteNumber,
        public ?string $invoiceNumber,
        public string $invoiceStatus,
        public ?string $invoiceTotal,
        public int $lineCount,
        public int $rejectedLineCount,
    ) {}
}
