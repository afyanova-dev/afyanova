<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Application\StockContextResolver;
use App\Domains\Inventory\Domain\BatchRepository;
use App\Domains\Inventory\Domain\Exception\InventoryItemNotStockable;
use App\Domains\Inventory\Domain\Exception\StockBatchExpired;
use App\Domains\Inventory\Domain\Exception\StockTransferNotAllowed;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Domains\Inventory\Domain\Model\StockTransfer;
use App\Domains\Inventory\Domain\Service\StockLedgerService;
use App\Domains\Inventory\Domain\StockBalanceRepository;
use App\Domains\Inventory\Domain\StockTransferRepository;
use App\Domains\Inventory\Infrastructure\Sequence\MovementNumberSequence;
use App\Domains\Inventory\Infrastructure\Sequence\TransferNumberSequence;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Orchestrates `TransferStock` (Phase 8).
 *
 * Flow: resolve the active item and both active locations → both must belong
 * to one facility and differ → ADR-133 per-facility refinement
 * (`inventory.transfers.create`) → resolve the batch for a batch-tracked item
 * → lock BOTH balance rows in a stable location order (opposite transfers must
 * not deadlock) → append the immutable OUT movement at the source and the
 * matching IN movement at the destination → `StockLedgerService::postTransfer`
 * for each side (the OUT side enforces the negative-stock guard and leaves
 * reserved stock alone) → save both balances → record the transfer document
 * (one transaction).
 *
 * `StockBalanceRepository` is the first repository parameter so the bus
 * resolves `StockBalancePolicy` from it (the ADR-115 handler→policy rule).
 */
final class TransferStockHandler
{
    public function __construct(
        private readonly StockBalanceRepository $balances,
        private readonly BatchRepository $batches,
        private readonly StockContextResolver $resolver,
        private readonly StockLedgerService $ledger,
        private readonly MovementNumberSequence $movementNumbers,
        private readonly TransferNumberSequence $transferNumbers,
        private readonly TenantContext $context,
        private readonly StockTransferRepository $transfers,
    ) {}

    public function __invoke(TransferStock $command): void
    {
        $item = $this->resolver->resolveActiveItem($command->itemId);
        $from = $this->resolver->resolveActiveLocation($command->fromLocationId);
        $to = $this->resolver->resolveActiveLocation($command->toLocationId);

        if ($from->locationId === $to->locationId) {
            throw StockTransferNotAllowed::sameLocation($from->locationId);
        }

        if ($from->facilityId !== $to->facilityId) {
            throw StockTransferNotAllowed::crossFacility($from->locationId, $to->locationId);
        }

        $this->resolver->assertActorScopedToFacility(
            $from->facilityId,
            'inventory.transfers.create',
            $command->transferId,
        );

        $tenantId = $this->context->tenantId();
        $now = CarbonImmutable::now();
        $movedAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;
        $reasonText = $command->reason !== null && trim($command->reason) !== '' ? trim($command->reason) : null;

        $batchNumber = $command->batchNumber !== null && trim($command->batchNumber) !== ''
            ? trim($command->batchNumber)
            : null;

        $batchId = null;
        if ($item->trackBatch) {
            if ($batchNumber === null) {
                throw InventoryItemNotStockable::batchNumberRequired($item->itemId);
            }

            $batch = $this->batches->findByItemAndNumber($tenantId, $item->itemId, $batchNumber)
                ?? throw NotFound::aggregate(Batch::class, $batchNumber);

            if ($batch->isExpired()) {
                throw StockBatchExpired::forBatch(
                    $item->itemId,
                    $from->locationId,
                    $batchNumber,
                    $batch->expiry_date?->toDateString(),
                );
            }

            $batchId = (string) $batch->id;
        }

        // Lock both rows in a stable order: two transfers moving stock in
        // opposite directions must not wait on each other in opposite order.
        [$firstLocationId, $secondLocationId] = strcmp($from->locationId, $to->locationId) < 0
            ? [$from->locationId, $to->locationId]
            : [$to->locationId, $from->locationId];

        $balanced = [];
        foreach ([$firstLocationId, $secondLocationId] as $locationId) {
            $balanced[$locationId] = $this->balances->forUpdateOrCreate(
                $tenantId,
                $from->facilityId,
                $item->itemId,
                $locationId,
                $batchId,
            );
        }

        $fromBalance = $balanced[$from->locationId];
        $toBalance = $balanced[$to->locationId];

        $outMovement = StockMovement::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $from->facilityId,
            'movement_number' => $this->movementNumbers->next($tenantId),
            'movement_type' => StockMovement::TYPE_TRANSFER_OUT,
            'direction' => StockMovement::DIRECTION_OUT,
            'quantity' => $command->quantity,
            'uom' => $item->uom,
            'item_id' => $item->itemId,
            'location_id' => $from->locationId,
            'batch_id' => $batchId,
            'source_type' => 'TRANSFER',
            'source_ref' => $command->transferId,
            'po_ref' => null,
            'reason_text' => $reasonText,
            'actor_user_id' => $command->actorId,
            'occurred_at' => $movedAt,
            'recorded_at' => $now,
            'version' => 0,
        ]);

        $inMovement = StockMovement::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $from->facilityId,
            'movement_number' => $this->movementNumbers->next($tenantId),
            'movement_type' => StockMovement::TYPE_TRANSFER_IN,
            'direction' => StockMovement::DIRECTION_IN,
            'quantity' => $command->quantity,
            'uom' => $item->uom,
            'item_id' => $item->itemId,
            'location_id' => $to->locationId,
            'batch_id' => $batchId,
            'source_type' => 'TRANSFER',
            'source_ref' => $command->transferId,
            'po_ref' => null,
            'reason_text' => $reasonText,
            'actor_user_id' => $command->actorId,
            'occurred_at' => $movedAt,
            'recorded_at' => $now,
            'version' => 0,
        ]);

        $this->ledger->postTransfer($fromBalance, $outMovement, $to->locationId);
        $this->ledger->postTransfer($toBalance, $inMovement, $from->locationId);

        $this->balances->save($fromBalance);
        $this->balances->save($toBalance);

        $this->transfers->save(StockTransfer::record(
            id: $command->transferId,
            tenantId: $tenantId,
            facilityId: $from->facilityId,
            transferNumber: $this->transferNumbers->next($tenantId),
            itemId: $item->itemId,
            fromLocationId: $from->locationId,
            toLocationId: $to->locationId,
            batchId: $batchId,
            quantity: $command->quantity,
            reasonText: $reasonText,
            outMovementId: (string) $outMovement->id,
            inMovementId: (string) $inMovement->id,
            movedByUserId: $command->actorId,
            movedAt: $movedAt,
        ));
    }
}
