<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Listener;

use App\Domains\Inventory\Contracts\Event\StockReceived;
use App\Domains\Procurement\Application\Command\RecordPurchaseOrderReceipt;
use App\Shared\Bus\CommandBus;
use Illuminate\Support\Str;

/**
 * Reacts to Inventory's goods-receipt fact and projects it onto Purchase-Order
 * fulfilment (19-procurement-domain.md §125 / §126; ADR-172 D-C).
 *
 * The FIRST event listener in the engagement (IC §7.3 shape verbatim). The
 * `OutboxRelay` hydrates the event and dispatches a queued `DispatchListenerJob`
 * (a `TenantAwareJob`) that re-establishes the tenant context and invokes this
 * plain invokable. It does exactly one thing: map the event to the
 * `RecordPurchaseOrderReceipt` system command, keyed for idempotency on the
 * event id. All matching / reconciliation lives in the handler.
 *
 * `poRef` is treated as the PO's `po_number` (ADR-172 D-B). A receipt with no
 * `poRef` (donation / opening balance) is not a Procurement concern.
 */
final class OnStockReceived
{
    public function __construct(private readonly CommandBus $bus) {}

    public function __invoke(StockReceived $event): void
    {
        // P1-03: a multi-line goods receipt carries a goodsReceiptRef. Its
        // document-level GoodsReceiptPosted event performs ONE batch projection;
        // exiting here prevents the same PO line being counted twice.
        if ($event->goodsReceiptRef !== null) {
            return;
        }

        if ($event->poRef === null || trim($event->poRef) === '') {
            return;
        }

        $this->bus->dispatch(new RecordPurchaseOrderReceipt(
            poNumber: trim($event->poRef),
            sourceEventId: $event->eventId,
            itemRef: $event->itemId,
            batchRef: $event->batchId !== null && Str::isUuid($event->batchId) ? $event->batchId : null,
            locationRef: $event->locationId,
            movementId: $event->movementId,
            movementNumber: $event->movementNumber,
            quantity: $event->quantity,
            uom: $event->uom,
            facilityId: $event->facilityId,
            sourceType: $event->sourceType,
            occurredAt: $event->occurredAt,
            idempotencyKey: $event->eventId,
            poLineRef: $event->poLineRef,
            goodsReceiptRef: $event->goodsReceiptRef,
        ));
    }
}
