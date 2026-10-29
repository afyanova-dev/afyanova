<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Application\StockContextResolver;
use App\Domains\Inventory\Domain\BatchRepository;
use App\Domains\Inventory\Domain\Exception\InventoryItemNotStockable;
use App\Domains\Inventory\Domain\Exception\PackEntryInconsistent;
use App\Domains\Inventory\Domain\Exception\StockBatchAlreadyExpired;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Domains\Inventory\Domain\Quantity;
use App\Domains\Inventory\Domain\Service\StockLedgerService;
use App\Domains\Inventory\Domain\StockBalanceRepository;
use App\Domains\Inventory\Infrastructure\Sequence\MovementNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `ReceiveStock` (ADR-170 D-I / D-L).
 *
 * Flow: resolve active item + active location (→ facility) → ADR-133 per-facility
 * refinement (`inventory.receipts.create`) → enforce the item's tracking rules
 * (batch number / expiry date) → find-or-create the batch → allocate `MOV-%08d`
 * → append the immutable `inv_stock_movements` row → load-or-create the
 * `inv_stock_balances` row `FOR UPDATE` → `StockLedgerService::post` (applies
 * the receipt, records `StockReceived`) → versioned save (one txn).
 *
 * `StockBalanceRepository` is the first repository parameter so the bus
 * resolves `StockBalancePolicy` (the ADR-115 handler→policy rule).
 */
final class ReceiveStockHandler
{
    public function __construct(
        private readonly StockBalanceRepository $balances,
        private readonly BatchRepository $batches,
        private readonly StockContextResolver $resolver,
        private readonly StockLedgerService $ledger,
        private readonly MovementNumberSequence $movementNumbers,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(ReceiveStock $command): void
    {
        $item = $this->resolver->resolveActiveItem($command->itemId);
        $location = $this->resolver->resolveActiveLocation($command->locationId);

        $this->resolver->assertActorScopedToFacility(
            $location->facilityId,
            'inventory.receipts.create',
            $command->movementId,
        );

        $tenantId = $this->context->tenantId();
        $now = CarbonImmutable::now();
        $receivedAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;

        // "What arrived" record — the base `quantity` is the truth; the entered
        // unit/quantity/pack size must agree with it and name a unit the item
        // is stocked or bought in (inventory-item-master-spec.md §7).
        $enteredUnit = $command->enteredUnit !== null ? strtoupper(trim($command->enteredUnit)) : null;
        $enteredUnit = ($enteredUnit === null || $enteredUnit === '') ? null : $enteredUnit;
        $enteredQuantity = null;
        $packSize = null;

        if ($enteredUnit !== null) {
            $enteredQuantity = Quantity::of((string) $command->enteredQuantity);
            $baseUom = strtoupper((string) $item->uom);
            $itemPackUnit = $item->packUnit !== null ? strtoupper((string) $item->packUnit) : null;

            if ($enteredUnit === $baseUom) {
                if ($command->packSize !== null && trim((string) $command->packSize) !== '') {
                    throw PackEntryInconsistent::quantityMismatch(
                        Quantity::mul($enteredQuantity, (string) $command->packSize),
                        $command->quantity,
                    );
                }

                if (Quantity::compare($enteredQuantity, $command->quantity) !== 0) {
                    throw PackEntryInconsistent::quantityMismatch($enteredQuantity, $command->quantity);
                }
            } elseif ($itemPackUnit !== null && $enteredUnit === $itemPackUnit) {
                if ($command->packSize === null || trim((string) $command->packSize) === '') {
                    throw PackEntryInconsistent::missingPackSize($enteredUnit);
                }

                $packSize = Quantity::of((string) $command->packSize);

                if (Quantity::compare(Quantity::mul($enteredQuantity, $packSize), $command->quantity) !== 0) {
                    throw PackEntryInconsistent::quantityMismatch(
                        Quantity::mul($enteredQuantity, $packSize),
                        $command->quantity,
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
        }

        $batchNumber = $command->batchNumber !== null && trim($command->batchNumber) !== ''
            ? trim($command->batchNumber)
            : null;

        $batchId = null;
        if ($item->trackBatch) {
            if ($batchNumber === null) {
                throw InventoryItemNotStockable::batchNumberRequired($item->itemId);
            }

            if ($item->trackExpiry) {
                if ($command->expiryDate === null || trim($command->expiryDate) === '') {
                    throw InventoryItemNotStockable::expiryRequired($item->itemId);
                }

                // A batch must not be received once it is expired as of the
                // receipt's effective date (Phase 0 receipt-source policy).
                if (CarbonImmutable::parse($command->expiryDate)->startOfDay()->lessThan($receivedAt->startOfDay())) {
                    throw StockBatchAlreadyExpired::forItem(
                        $item->itemId,
                        $batchNumber,
                        $command->expiryDate,
                        $receivedAt->toDateString(),
                    );
                }
            }

            $existing = $this->batches->findByItemAndNumber($tenantId, $item->itemId, $batchNumber);

            if ($existing !== null) {
                $batchId = (string) $existing->id;
            } else {
                $batch = Batch::register(
                    tenantId: $tenantId,
                    itemId: $item->itemId,
                    batchNumber: $batchNumber,
                    expiryDate: $command->expiryDate,
                    manufactureDate: $command->manufactureDate,
                    supplierRef: $command->supplierRef,
                    receivedAt: $now,
                );
                $this->batches->save($batch);
                $batchId = (string) $batch->id;
            }
        }

        $movement = StockMovement::create([
            'id' => $command->movementId,
            'tenant_id' => $tenantId,
            'facility_id' => $location->facilityId,
            'movement_number' => $this->movementNumbers->next($tenantId),
            'movement_type' => StockMovement::TYPE_RECEIPT,
            'direction' => StockMovement::DIRECTION_IN,
            'quantity' => $command->quantity,
            'uom' => $item->uom,
            'entered_unit' => $enteredUnit,
            'entered_quantity' => $enteredQuantity,
            'pack_size' => $packSize,
            'item_id' => $item->itemId,
            'location_id' => $location->locationId,
            'batch_id' => $batchId,
            'source_type' => strtoupper(trim($command->sourceType)),
            'source_ref' => null,
            'po_ref' => $command->poRef !== null && trim($command->poRef) !== '' ? trim($command->poRef) : null,
            'po_line_ref' => $command->poLineRef,
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
}
