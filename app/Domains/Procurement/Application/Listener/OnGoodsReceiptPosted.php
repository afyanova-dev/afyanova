<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Listener;

use App\Domains\Inventory\Contracts\Event\GoodsReceiptPosted;
use App\Domains\Procurement\Application\Command\RecordPurchaseOrderReceiptBatch;
use App\Domains\Procurement\Application\Command\RecordPurchaseOrderReceiptBatchLine;
use App\Domains\Procurement\Domain\Quantity;
use App\Shared\Bus\CommandBus;
use Illuminate\Support\Str;

/**
 * Projects Inventory's document-level goods-receipt fact onto PO fulfilment
 * once per delivery (P1-03).
 *
 * A 500-line GRN emits many `StockReceived` facts but ONE
 * `GoodsReceiptPosted`. This listener turns the accepted lines into a single
 * `RecordPurchaseOrderReceiptBatch` so Procurement locks the PO once and
 * performs one status rollup. The per-line `OnStockReceived` listener exits
 * when `goodsReceiptRef` is present, preventing a double projection.
 */
final class OnGoodsReceiptPosted
{
    public function __construct(private readonly CommandBus $bus) {}

    public function __invoke(GoodsReceiptPosted $event): void
    {
        if ($event->poRef === null || trim($event->poRef) === '') {
            return;
        }

        $lines = [];

        foreach ($event->lines as $line) {
            if (! Quantity::isPositive($line['quantityAccepted'])) {
                continue;
            }

            if ($line['poLineRef'] === null || $line['movementId'] === null || $line['movementNumber'] === null) {
                continue;
            }

            if (! Str::isUuid($line['poLineRef']) || ! Str::isUuid($line['movementId'])) {
                continue;
            }

            // P1-11 — a substitution posts the substitute item to stock, but
            // the PO line is reconciled against ITS item, not the substitute.
            $poItemRef = $line['substitutedForItemId'] ?? $line['itemId'];

            $lines[] = new RecordPurchaseOrderReceiptBatchLine(
                receiptLineRef: $line['receiptLineId'],
                poLineRef: $line['poLineRef'],
                itemRef: $poItemRef,
                batchRef: $line['batchId'] !== null && Str::isUuid($line['batchId']) ? $line['batchId'] : null,
                locationRef: $event->locationId,
                movementId: $line['movementId'],
                movementNumber: $line['movementNumber'],
                quantity: $line['quantityAccepted'],
                uom: $line['uom'],
            );
        }

        if ($lines === []) {
            return;
        }

        $this->bus->dispatch(new RecordPurchaseOrderReceiptBatch(
            poNumber: trim($event->poRef),
            sourceEventId: $event->eventId,
            facilityId: $event->facilityId,
            occurredAt: $event->occurredAt,
            goodsReceiptRef: $event->goodsReceiptId,
            lines: $lines,
            idempotencyKey: $event->eventId,
        ));
    }
}
