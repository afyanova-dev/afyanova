<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * One goods-receipt document with its lines (Phase 1 / P1-07–P1-08).
 */
final readonly class GoodsReceiptDetail
{
    /**
     * @param  list<GoodsReceiptLineSummary>  $lines
     */
    public function __construct(
        public string $goodsReceiptId,
        public string $facilityId,
        public string $receiptNumber,
        public string $receivedAt,
        public ?string $recordedAt,
        public string $sourceType,
        public ?string $poRef,
        public ?string $supplierRef,
        public string $locationId,
        public string $locationCode,
        public string $locationName,
        public ?string $deliveryNoteNumber,
        public ?string $deliveryNoteMissingReason,
        public ?string $invoiceNumber,
        public ?string $invoiceDate,
        public string $invoiceStatus,
        public string $invoiceCurrency,
        public ?string $invoiceTotal,
        public string $status,
        public string $matchStatus,
        public ?string $remarks,
        public string $receivedByUserId,
        public array $lines,
    ) {}
}
