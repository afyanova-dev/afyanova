<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Project ONE Inventory `GoodsReceiptPosted` fact onto Purchase-Order fulfilment
 * (Phase 1 / P1-03; 19-procurement-domain.md §125–§129; ADR-172 D-D).
 *
 * A SYSTEM command — dispatched only by `OnGoodsReceiptPosted`. It replaces the
 * per-line `StockReceived` projection for multi-line goods receipts so a
 * 500-line delivery produces ONE Procurement job, ONE PO lock, and ONE status
 * rollup. The per-line `OnStockReceived` listener exits immediately when the
 * event carries a `goodsReceiptRef`.
 *
 * `idempotencyKey` is the `GoodsReceiptPosted` eventId (layer 1). The DB
 * backstop is `pcr_po_receipts.unique(tenant_id, goods_receipt_line_ref)`
 * (layer 2), because one event now covers many lines.
 */
final readonly class RecordPurchaseOrderReceiptBatch
{
    /**
     * @param  list<RecordPurchaseOrderReceiptBatchLine>  $lines
     */
    public function __construct(
        public string $poNumber,
        public string $sourceEventId,
        public string $facilityId,
        public string $occurredAt,
        public string $goodsReceiptRef,
        public array $lines,
        public ?string $idempotencyKey = null,
    ) {
        foreach ([
            'sourceEventId' => $sourceEventId,
            'facilityId' => $facilityId,
            'goodsReceiptRef' => $goodsReceiptRef,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordPurchaseOrderReceiptBatch::\${$name} must be a UUID (IC §3).");
            }
        }

        if (trim($poNumber) === '') {
            throw new InvalidArgumentException('RecordPurchaseOrderReceiptBatch::$poNumber must not be blank.');
        }

        if ($lines === []) {
            throw new InvalidArgumentException('RecordPurchaseOrderReceiptBatch::$lines must contain at least one accepted line.');
        }

        $seen = [];
        foreach ($lines as $line) {
            if (isset($seen[$line->receiptLineRef])) {
                throw new InvalidArgumentException('RecordPurchaseOrderReceiptBatch::$lines contains a duplicate receipt line reference.');
            }
            $seen[$line->receiptLineRef] = true;
        }

        try {
            CarbonImmutable::parse($occurredAt);
        } catch (Throwable) {
            throw new InvalidArgumentException('RecordPurchaseOrderReceiptBatch::$occurredAt must be an ISO-8601 datetime.');
        }
    }
}
