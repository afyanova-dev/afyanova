<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Quantity;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One accepted goods-receipt line projected onto a Purchase-Order line
 * (Phase 1 / P1-03).
 *
 * A SYSTEM DTO carried by {@see RecordPurchaseOrderReceiptBatch}. The
 * `poLineRef` is the exact Purchase-Order line chosen at receiving time and is
 * always authoritative; `receiptLineRef` is the Inventory goods-receipt line id
 * and is the idempotency key for the `pcr_po_receipts` append.
 */
final readonly class RecordPurchaseOrderReceiptBatchLine
{
    public function __construct(
        public string $receiptLineRef,
        public string $poLineRef,
        public string $itemRef,
        public ?string $batchRef,
        public string $locationRef,
        public string $movementId,
        public string $movementNumber,
        public string $quantity,
        public string $uom,
    ) {
        foreach ([
            'receiptLineRef' => $receiptLineRef,
            'poLineRef' => $poLineRef,
            'itemRef' => $itemRef,
            'locationRef' => $locationRef,
            'movementId' => $movementId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordPurchaseOrderReceiptBatchLine::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($batchRef !== null && ! Str::isUuid($batchRef)) {
            throw new InvalidArgumentException('RecordPurchaseOrderReceiptBatchLine::$batchRef must be a UUID or null.');
        }

        if (! is_numeric($quantity) || ! Quantity::isPositive($quantity)) {
            throw new InvalidArgumentException('RecordPurchaseOrderReceiptBatchLine::$quantity must be a positive decimal string.');
        }
    }
}
