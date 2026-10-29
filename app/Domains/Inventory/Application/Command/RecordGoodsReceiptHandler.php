<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Application\StockContextResolver;
use App\Domains\Inventory\Domain\BatchRepository;
use App\Domains\Inventory\Domain\Exception\InventoryItemNotStockable;
use App\Domains\Inventory\Domain\Exception\PackEntryInconsistent;
use App\Domains\Inventory\Domain\Exception\StockBatchAlreadyExpired;
use App\Domains\Inventory\Domain\GoodsReceiptRepository;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Domains\Inventory\Domain\Model\GoodsReceipt;
use App\Domains\Inventory\Domain\Model\GoodsReceiptLine;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Domains\Inventory\Domain\Quantity;
use App\Domains\Inventory\Domain\Service\StockLedgerService;
use App\Domains\Inventory\Domain\StockBalanceRepository;
use App\Domains\Inventory\Infrastructure\Sequence\GoodsReceiptNumberSequence;
use App\Domains\Inventory\Infrastructure\Sequence\MovementNumberSequence;
use App\Domains\Procurement\Contracts\ProcurementApi;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Orchestrates a multi-line goods receipt (Phase 1).
 *
 * One physical delivery → one GRN header → one line per accepted/rejected
 * arrival → one stock movement for the ACCEPTED quantity only. Everything runs
 * inside the command bus transaction, so a bad line leaves no header, no
 * movement and no balance change.
 *
 * The legacy single-line HTTP endpoint is retired; this handler is the
 * receiving workbench's path.
 */
final class RecordGoodsReceiptHandler
{
    public function __construct(
        private readonly StockBalanceRepository $balances,
        private readonly BatchRepository $batches,
        private readonly GoodsReceiptRepository $receipts,
        private readonly StockContextResolver $resolver,
        private readonly StockLedgerService $ledger,
        private readonly MovementNumberSequence $movementNumbers,
        private readonly GoodsReceiptNumberSequence $receiptNumbers,
        private readonly TenantContext $context,
        private readonly ProcurementApi $procurement,
    ) {}

    public function __invoke(RecordGoodsReceipt $command): void
    {
        $location = $this->resolver->resolveActiveLocation($command->locationId);

        $this->resolver->assertActorScopedToFacility(
            $location->facilityId,
            'inventory.receipts.create',
            $command->receiptId,
        );

        $tenantId = $this->context->tenantId();
        $now = CarbonImmutable::now();
        $receivedAt = $command->receivedAt !== null
            ? CarbonImmutable::parse($command->receivedAt)
            : $now;

        $receipt = GoodsReceipt::record(
            id: $command->receiptId,
            tenantId: $tenantId,
            facilityId: $location->facilityId,
            receiptNumber: $this->receiptNumbers->next($tenantId),
            sourceType: $command->sourceType,
            poRef: $command->poRef,
            supplierRef: $command->supplierRef,
            locationId: $location->locationId,
            deliveryNoteNumber: $command->deliveryNoteNumber,
            deliveryNoteMissingReason: $command->deliveryNoteMissingReason,
            invoiceNumber: $command->invoiceNumber,
            invoiceDate: $command->invoiceDate,
            invoiceStatus: $command->invoiceStatus,
            invoiceCurrency: $command->invoiceCurrency,
            invoiceTotal: $command->invoiceTotal,
            receivedAt: $receivedAt,
            receivedByUserId: $command->actorId,
            remarks: $command->remarks,
            occurredAt: $receivedAt,
            recordedAt: $now,
        );
        $purchaseOrder = $receipt->source_type === GoodsReceipt::SOURCE_PROCUREMENT
            ? $this->procurement->goodsReceiptPurchaseOrder($location->facilityId, (string) $receipt->po_ref)
            : null;
        if ($receipt->source_type === GoodsReceipt::SOURCE_PROCUREMENT && $purchaseOrder === null) {
            throw new InvalidArgumentException('The purchase order for this receipt could not be found.');
        }
        if ($purchaseOrder !== null && $purchaseOrder->facilityId !== $location->facilityId) {
            throw new InvalidArgumentException('The purchase order does not belong to the active facility.');
        }
        $poLines = collect($purchaseOrder === null ? [] : $purchaseOrder->lines)->keyBy('lineId');

        // Persist the header first so line FK inserts are valid; the document
        // event is recorded after all lines have been written, in the same txn.
        $this->receipts->save($receipt);

        $eventLines = [];

        foreach ($command->lines as $line) {
            $item = $this->resolver->resolveActiveItem($line->itemId);
            $poLine = $line->poLineRef !== null ? $poLines->get($line->poLineRef) : null;
            if ($receipt->source_type === GoodsReceipt::SOURCE_PROCUREMENT
                && $poLine === null) {
                throw new InvalidArgumentException('A receipt line must reference a line on its selected purchase order.');
            }

            if ($item->trackSerial) {
                throw InventoryItemNotStockable::serialised($item->itemId);
            }

            $enteredUnit = strtoupper(trim($line->enteredUnit));
            $baseUom = strtoupper((string) $item->uom);
            $itemPackUnit = $item->packUnit !== null ? strtoupper((string) $item->packUnit) : null;

            if ($enteredUnit === $baseUom) {
                if (Quantity::compare($line->packSize, '1') !== 0) {
                    throw PackEntryInconsistent::quantityMismatch(
                        Quantity::mul($line->enteredQuantity, $line->packSize),
                        $line->quantityDelivered,
                    );
                }
            } elseif ($itemPackUnit !== null && $enteredUnit === $itemPackUnit) {
                if (Quantity::compare(Quantity::mul($line->enteredQuantity, $line->packSize), $line->quantityDelivered) !== 0) {
                    throw PackEntryInconsistent::quantityMismatch(
                        Quantity::mul($line->enteredQuantity, $line->packSize),
                        $line->quantityDelivered,
                    );
                }
            } else {
                throw PackEntryInconsistent::unitNotUsed(
                    (string) $item->code,
                    $baseUom,
                    $itemPackUnit,
                    $enteredUnit,
                );
            }

            $batchNumber = $line->batchNumber !== null && trim($line->batchNumber) !== ''
                ? trim($line->batchNumber)
                : null;

            if ($item->trackBatch && $batchNumber === null) {
                throw InventoryItemNotStockable::batchNumberRequired($item->itemId);
            }

            if ($item->trackBatch && $item->trackExpiry) {
                if ($line->expiryDate === null || trim($line->expiryDate) === '') {
                    throw InventoryItemNotStockable::expiryRequired($item->itemId);
                }

                if (CarbonImmutable::parse($line->expiryDate)->startOfDay()->lessThan($receivedAt->startOfDay())) {
                    throw StockBatchAlreadyExpired::forItem(
                        $item->itemId,
                        (string) $batchNumber,
                        $line->expiryDate,
                        $receivedAt->toDateString(),
                    );
                }
            }

            $accepted = $line->acceptedQuantity();

            if (Quantity::isPositive($line->quantityRejected)
                && ($line->rejectionReason === null || trim($line->rejectionReason) === '')) {
                throw new InvalidArgumentException('A rejection reason is required when a receipt line rejects quantity.');
            }

            $batchId = null;
            $movementId = null;
            $movementNumber = null;

            if (Quantity::isPositive($accepted)) {
                if ($item->trackBatch) {
                    $existing = $this->batches->findByItemAndNumber($tenantId, $item->itemId, (string) $batchNumber);

                    if ($existing !== null) {
                        $batchId = (string) $existing->id;
                    } else {
                        $batch = Batch::register(
                            tenantId: $tenantId,
                            itemId: $item->itemId,
                            batchNumber: (string) $batchNumber,
                            expiryDate: $line->expiryDate,
                            manufactureDate: $line->manufactureDate,
                            supplierRef: $command->supplierRef,
                            receivedAt: $now,
                        );
                        $this->batches->save($batch);
                        $batchId = (string) $batch->id;
                    }
                }

                $movementId = (string) Str::uuid7();
                $movementNumber = $this->movementNumbers->next($tenantId);

                $movement = StockMovement::create([
                    'id' => $movementId,
                    'tenant_id' => $tenantId,
                    'facility_id' => $location->facilityId,
                    'movement_number' => $movementNumber,
                    'movement_type' => StockMovement::TYPE_RECEIPT,
                    'direction' => StockMovement::DIRECTION_IN,
                    'quantity' => $accepted,
                    'uom' => $item->uom,
                    'entered_unit' => $enteredUnit,
                    'entered_quantity' => $line->enteredQuantity,
                    'pack_size' => $line->packSize,
                    'item_id' => $item->itemId,
                    'location_id' => $location->locationId,
                    'batch_id' => $batchId,
                    'source_type' => $receipt->source_type,
                    'source_ref' => (string) $receipt->id,
                    'po_ref' => $receipt->po_ref,
                    'po_line_ref' => $line->poLineRef,
                    'goods_receipt_ref' => (string) $receipt->id,
                    'reason_text' => null,
                    'actor_user_id' => $command->actorId,
                    'occurred_at' => $receivedAt,
                    'recorded_at' => $now,
                    'version' => 0,
                ]);

                $balance = $this->balances->forUpdateOrCreate(
                    $tenantId,
                    $location->facilityId,
                    $item->itemId,
                    $location->locationId,
                    $batchId,
                );

                $this->ledger->post($balance, $movement);
                $this->balances->save($balance);
            }

            GoodsReceiptLine::create([
                'id' => $line->receiptLineId,
                'tenant_id' => $tenantId,
                'receipt_id' => (string) $receipt->id,
                'item_id' => $item->itemId,
                'po_line_ref' => $line->poLineRef,
                'batch_id' => $batchId,
                'movement_id' => $movementId,
                'entered_unit' => $enteredUnit,
                'entered_quantity' => $line->enteredQuantity,
                'pack_size' => $line->packSize,
                'quantity_delivered' => $line->quantityDelivered,
                'quantity_rejected' => $line->quantityRejected,
                'uom' => $item->uom,
                'po_unit' => $poLine?->unit,
                'po_unit_price' => $poLine?->unitPrice,
                'batch_number' => $batchNumber,
                'expiry_date' => $line->expiryDate,
                'manufacture_date' => $line->manufactureDate,
                'condition' => $line->condition,
                'substituted_for_item_id' => $line->substitutedForItemId,
                'condition_notes' => $line->conditionNotes !== null && trim($line->conditionNotes) !== ''
                    ? trim($line->conditionNotes)
                    : null,
                'rejection_reason' => $line->rejectionReason !== null && trim($line->rejectionReason) !== ''
                    ? trim($line->rejectionReason)
                    : null,
            ]);

            $eventLines[] = [
                'receiptLineId' => $line->receiptLineId,
                'poLineRef' => $line->poLineRef,
                'itemId' => $item->itemId,
                'batchId' => $batchId,
                'movementId' => $movementId,
                'movementNumber' => $movementNumber,
                'quantityAccepted' => $accepted,
                'quantityRejected' => Quantity::of($line->quantityRejected),
                'uom' => (string) $item->uom,
                'batchNumber' => $batchNumber,
                'expiryDate' => $line->expiryDate,
                'condition' => $line->condition,
                'substitutedForItemId' => $line->substitutedForItemId,
            ];
        }

        $receipt->markPosted($eventLines);
        $this->receipts->save($receipt);
    }
}
