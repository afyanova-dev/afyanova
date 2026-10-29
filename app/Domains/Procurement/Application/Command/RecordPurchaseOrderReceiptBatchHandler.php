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
 * Orchestrates the ONE-job projection of a multi-line goods receipt (P1-03).
 *
 * Resolve the PO by number once (`FOR UPDATE`) → guard status → for each
 * accepted receipt line, append `pcr_po_receipts` keyed by its exact PO line and
 * goods-receipt line → add accepted quantity to that line → recompute the
 * receipt-tail status once. A receipt that cannot be reconciled is LOGGED,
 * never dead-lettered (ADR-172 D-I).
 *
 * Only ACCEPTED quantity is carried by the caller; rejected quantity never moves
 * the PO's `received_qty`.
 */
final class RecordPurchaseOrderReceiptBatchHandler
{
    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly PurchaseOrderLifecycle $lifecycle,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RecordPurchaseOrderReceiptBatch $command): void
    {
        $po = $this->orders->findByNumberForUpdate($command->poNumber);

        if ($po === null) {
            Log::warning('procurement.receipt.batch_unmatched_po', [
                'po_number' => $command->poNumber,
                'source_event_id' => $command->sourceEventId,
            ]);

            return;
        }

        try {
            $this->lifecycle->assertCanRecordReceipt((string) $po->status);
        } catch (IllegalPurchaseOrderTransition) {
            Log::warning('procurement.receipt.batch_unexpected_po_state', [
                'purchase_order_id' => (string) $po->id,
                'po_number' => $command->poNumber,
                'status' => (string) $po->status,
                'source_event_id' => $command->sourceEventId,
            ]);

            return;
        }

        if ($command->facilityId !== (string) $po->facility_id) {
            Log::warning('procurement.receipt.batch_facility_mismatch', [
                'purchase_order_id' => (string) $po->id,
                'po_facility_id' => (string) $po->facility_id,
                'receipt_facility_id' => $command->facilityId,
                'source_event_id' => $command->sourceEventId,
            ]);
        }

        $mutated = false;

        foreach ($command->lines as $lineCommand) {
            $line = PoLine::query()
                ->where('po_id', $po->id)
                ->whereKey($lineCommand->poLineRef)
                ->where('status', PoLine::STATUS_OPEN)
                ->first();

            if ($line === null) {
                Log::warning('procurement.receipt.batch_line_not_open', [
                    'purchase_order_id' => (string) $po->id,
                    'po_number' => $command->poNumber,
                    'po_line_ref' => $lineCommand->poLineRef,
                    'source_event_id' => $command->sourceEventId,
                ]);

                continue;
            }

            if ((string) $line->item_ref !== $lineCommand->itemRef) {
                Log::warning('procurement.receipt.batch_line_item_mismatch', [
                    'purchase_order_id' => (string) $po->id,
                    'po_number' => $command->poNumber,
                    'po_line_ref' => $lineCommand->poLineRef,
                    'line_item_ref' => (string) $line->item_ref,
                    'receipt_item_ref' => $lineCommand->itemRef,
                    'source_event_id' => $command->sourceEventId,
                ]);

                continue;
            }

            try {
                PoReceipt::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $this->context->tenantId(),
                    'facility_id' => $command->facilityId,
                    'po_id' => (string) $po->id,
                    'po_line_id' => (string) $line->id,
                    'goods_receipt_ref' => $command->goodsReceiptRef,
                    'goods_receipt_line_ref' => $lineCommand->receiptLineRef,
                    'source_event_id' => $command->sourceEventId,
                    'movement_id' => $lineCommand->movementId,
                    'movement_number' => $lineCommand->movementNumber,
                    'item_ref' => $lineCommand->itemRef,
                    'batch_ref' => $lineCommand->batchRef,
                    'location_ref' => $lineCommand->locationRef,
                    'quantity' => $lineCommand->quantity,
                    'uom' => $lineCommand->uom,
                    'source_type' => 'PROCUREMENT',
                    'received_at' => CarbonImmutable::parse($command->occurredAt),
                    'recorded_at' => CarbonImmutable::now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                continue; // already projected — redelivered event
            }

            $line->received_qty = Quantity::add((string) $line->received_qty, $lineCommand->quantity);
            $line->save();
            $mutated = true;
        }

        if (! $mutated) {
            return;
        }

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
