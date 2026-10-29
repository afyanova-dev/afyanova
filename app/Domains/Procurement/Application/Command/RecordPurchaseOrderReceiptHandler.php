<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Exception\IllegalPurchaseOrderTransition;
use App\Domains\Procurement\Domain\Model\PoLine;
use App\Domains\Procurement\Domain\Model\PoReceipt;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\PurchaseOrderRepository;
use App\Domains\Procurement\Domain\Quantity;
use App\Domains\Procurement\Domain\Service\PurchaseOrderLifecycle;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates `RecordPurchaseOrderReceipt` (ADR-172 D-D / D-G / D-I).
 *
 * Resolve the PO by number (`FOR UPDATE`) → guard its status → use the exact
 * OPEN line selected by the receiver (`poLineRef`), falling back to the
 * earliest OPEN line for the item only for legacy events → append
 * `pcr_po_receipts` (unique on `source_event_id` — a violation is an idempotent
 * no-op) → add to that line's
 * `received_qty` → recompute the PARTIALLY_RECEIVED / FULLY_RECEIVED rollup over
 * the OPEN lines → roll the PO status if it changed. One bus transaction. A
 * receipt that cannot be reconciled is LOGGED, never dead-lettered (ADR-172
 * D-I). The `pcr_po_lines` mutation is the `AmendPurchaseOrderHandler` /
 * `ReplaceTeamMemberHandler` plain-child precedent.
 */
final class RecordPurchaseOrderReceiptHandler
{
    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly PurchaseOrderLifecycle $lifecycle,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RecordPurchaseOrderReceipt $command): void
    {
        $po = $this->orders->findByNumberForUpdate($command->poNumber);

        if ($po === null) {
            Log::warning('procurement.receipt.unmatched_po', [
                'po_number' => $command->poNumber,
                'source_event_id' => $command->sourceEventId,
                'movement_number' => $command->movementNumber,
            ]);

            return;
        }

        try {
            $this->lifecycle->assertCanRecordReceipt((string) $po->status);
        } catch (IllegalPurchaseOrderTransition) {
            Log::warning('procurement.receipt.unexpected_po_state', [
                'purchase_order_id' => (string) $po->id,
                'po_number' => $command->poNumber,
                'status' => (string) $po->status,
                'source_event_id' => $command->sourceEventId,
            ]);

            return;
        }

        if ($command->facilityId !== (string) $po->facility_id) {
            Log::warning('procurement.receipt.facility_mismatch', [
                'purchase_order_id' => (string) $po->id,
                'po_facility_id' => (string) $po->facility_id,
                'receipt_facility_id' => $command->facilityId,
                'source_event_id' => $command->sourceEventId,
            ]);
        }

        $line = null;

        if ($command->poLineRef !== null) {
            // P0-01 — the receiver's selected line is authoritative. Never fall
            // back to an item-code match when a line ID was supplied: duplicate
            // items on one PO must post to the exact line the clerk chose.
            $line = PoLine::query()
                ->where('po_id', $po->id)
                ->whereKey($command->poLineRef)
                ->where('status', PoLine::STATUS_OPEN)
                ->first();

            if ($line === null) {
                Log::warning('procurement.receipt.line_not_open', [
                    'purchase_order_id' => (string) $po->id,
                    'po_number' => $command->poNumber,
                    'po_line_ref' => $command->poLineRef,
                    'source_event_id' => $command->sourceEventId,
                ]);
            } elseif ((string) $line->item_ref !== $command->itemRef) {
                Log::warning('procurement.receipt.line_item_mismatch', [
                    'purchase_order_id' => (string) $po->id,
                    'po_number' => $command->poNumber,
                    'po_line_ref' => $command->poLineRef,
                    'line_item_ref' => (string) $line->item_ref,
                    'receipt_item_ref' => $command->itemRef,
                    'source_event_id' => $command->sourceEventId,
                ]);
                $line = null;
            }
        } else {
            // Legacy events created before P0-01 carry no line reference. Keep
            // the old item-code fallback only for replay of those events.
            $line = PoLine::query()
                ->where('po_id', $po->id)
                ->where('item_ref', $command->itemRef)
                ->where('status', PoLine::STATUS_OPEN)
                ->orderBy('line_no')
                ->first();
        }

        try {
            PoReceipt::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->context->tenantId(),
                'facility_id' => $command->facilityId,
                'po_id' => (string) $po->id,
                'po_line_id' => $line?->id,
                'goods_receipt_ref' => $command->goodsReceiptRef,
                'goods_receipt_line_ref' => $command->sourceEventId,
                'source_event_id' => $command->sourceEventId,
                'movement_id' => $command->movementId,
                'movement_number' => $command->movementNumber,
                'item_ref' => $command->itemRef,
                'batch_ref' => $command->batchRef,
                'location_ref' => $command->locationRef,
                'quantity' => $command->quantity,
                'uom' => $command->uom,
                'source_type' => strtoupper(trim($command->sourceType)),
                'received_at' => CarbonImmutable::parse($command->occurredAt),
                'recorded_at' => CarbonImmutable::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return; // already recorded — redelivered event
        }

        if ($line === null) {
            return; // recorded for provenance; the item is not on any line of the PO
        }

        $line->received_qty = Quantity::add((string) $line->received_qty, $command->quantity);
        $line->save();

        $newStatus = $this->rollup($po);

        if ($newStatus !== null && $newStatus !== (string) $po->status) {
            $po->markReceiptProgress($newStatus);
            $this->orders->save($po);
        }
    }

    /**
     * The receipt-tail status implied by the PO's OPEN lines (ADR-172 D-G).
     * SERVICE lines are ignored — they never receive stock. Returns null when
     * nothing has progressed (no OPEN line has any receipt).
     */
    private function rollup(PurchaseOrder $po): ?string
    {
        $lines = PoLine::query()
            ->where('po_id', $po->id)
            ->where('status', PoLine::STATUS_OPEN)
            ->where('line_type', PoLine::LINE_TYPE_STOCK)
            ->get(['ordered_base_qty', 'received_qty']);

        if ($lines->isEmpty()) {
            return null;
        }

        $anyReceived = false;
        $allSatisfied = true;

        foreach ($lines as $l) {
            $received = (string) $l->received_qty;
            if (Quantity::isPositive($received)) {
                $anyReceived = true;
            }
            // Receipts are always in the item's BASE unit, so reconcile against
            // the generated base quantity (3 BOX of 100 = 300), not the entered
            // quantity on a pack line.
            if (! Quantity::gte($received, (string) $l->ordered_base_qty)) {
                $allSatisfied = false;
            }
        }

        if ($allSatisfied) {
            return PurchaseOrder::STATUS_FULLY_RECEIVED;
        }

        return $anyReceived ? PurchaseOrder::STATUS_PARTIALLY_RECEIVED : null;
    }
}
