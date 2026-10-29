<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Application\StockContextResolver;
use App\Domains\Inventory\Domain\BatchRepository;
use App\Domains\Inventory\Domain\Exception\InventoryItemNotStockable;
use App\Domains\Inventory\Domain\Exception\StockBatchExpired;
use App\Domains\Inventory\Domain\Exception\StockReservationMismatch;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Domains\Inventory\Domain\Model\StockReservation;
use App\Domains\Inventory\Domain\Quantity;
use App\Domains\Inventory\Domain\Service\StockLedgerService;
use App\Domains\Inventory\Domain\Service\StockReservationLifecycle;
use App\Domains\Inventory\Domain\StockBalanceRepository;
use App\Domains\Inventory\Domain\StockReservationRepository;
use App\Domains\Inventory\Infrastructure\Sequence\MovementNumberSequence;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `RecordConsumption` (ADR-170 D-I / D-L).
 *
 * Flow: resolve active item + active location → ADR-133 per-facility refinement
 * (`inventory.consumption.record`) → resolve the batch (must already exist for
 * a batch-tracked item — consumption never creates one) → allocate `MOV-%08d`
 * → append the immutable OUT movement → load the balance `FOR UPDATE` →
 * `StockLedgerService::post` (guards Inv 5, records `StockConsumed`) →
 * versioned save (one txn).
 *
 * ADR-180 D-F — when `$command->reservationId` is set the consumption also
 * fulfils that ACTIVE reservation: the reservation is loaded `FOR UPDATE`, its
 * item / location / batch and `reserved_qty` are validated, `post()` releases
 * the consumed quantity from `reserved_qty`, and the reservation flips
 * FULFILLED. `StockReservationRepository` / `StockReservationLifecycle` are
 * LATER constructor parameters so the bus still resolves `StockBalancePolicy`
 * from `StockBalanceRepository` (first).
 */
final class RecordConsumptionHandler
{
    public function __construct(
        private readonly StockBalanceRepository $balances,
        private readonly BatchRepository $batches,
        private readonly StockContextResolver $resolver,
        private readonly StockLedgerService $ledger,
        private readonly MovementNumberSequence $movementNumbers,
        private readonly TenantContext $context,
        private readonly StockReservationRepository $reservations,
        private readonly StockReservationLifecycle $reservationLifecycle,
    ) {}

    public function __invoke(RecordConsumption $command): void
    {
        $item = $this->resolver->resolveActiveItem($command->itemId);
        $location = $this->resolver->resolveActiveLocation($command->locationId);

        $this->resolver->assertActorScopedToFacility(
            $location->facilityId,
            'inventory.consumption.record',
            $command->movementId,
        );

        $tenantId = $this->context->tenantId();
        $now = CarbonImmutable::now();

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
                    $location->locationId,
                    $batchNumber,
                    $batch->expiry_date?->toDateString(),
                );
            }
            $batchId = (string) $batch->id;
        }

        $movement = StockMovement::create([
            'id' => $command->movementId,
            'tenant_id' => $tenantId,
            'facility_id' => $location->facilityId,
            'movement_number' => $this->movementNumbers->next($tenantId),
            'movement_type' => StockMovement::TYPE_CONSUMPTION,
            'direction' => StockMovement::DIRECTION_OUT,
            'quantity' => $command->quantity,
            'uom' => $item->uom,
            'item_id' => $item->itemId,
            'location_id' => $location->locationId,
            'batch_id' => $batchId,
            'source_type' => strtoupper(trim($command->sourceType)),
            'source_ref' => $command->sourceRef !== null && trim($command->sourceRef) !== '' ? trim($command->sourceRef) : null,
            'po_ref' => null,
            'reason_text' => null,
            'actor_user_id' => $command->actorId,
            'occurred_at' => $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now,
            'recorded_at' => $now,
            'version' => 0,
        ]);

        $reservation = null;
        if ($command->reservationId !== null) {
            $reservation = $this->reservations->getByIdForUpdate($command->reservationId);
            $this->reservationLifecycle->assertActive('RecordConsumption', (string) $reservation->status);

            $matches = (string) $reservation->item_id === $item->itemId
                && (string) $reservation->location_id === $location->locationId
                && ($reservation->batch_id !== null ? (string) $reservation->batch_id : null) === $batchId;
            if (! $matches) {
                throw StockReservationMismatch::itemLocationBatch($command->reservationId);
            }

            if (Quantity::compare((string) $reservation->reserved_qty, $command->quantity) < 0) {
                throw StockReservationMismatch::insufficientReserved(
                    $command->reservationId,
                    Quantity::of((string) $reservation->reserved_qty),
                    Quantity::of($command->quantity),
                );
            }
        }

        $balance = $this->balances->forUpdateOrCreate(
            $tenantId,
            $location->facilityId,
            $item->itemId,
            $location->locationId,
            $batchId,
        );

        $this->ledger->post($balance, $movement, $reservation !== null ? $command->quantity : '0');

        $this->balances->save($balance);

        if ($reservation instanceof StockReservation) {
            $reservation->fulfill($command->movementId);
            $this->reservations->save($reservation);
        }
    }
}
