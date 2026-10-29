<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Application\StockContextResolver;
use App\Domains\Inventory\Domain\BatchRepository;
use App\Domains\Inventory\Domain\Exception\InventoryItemNotStockable;
use App\Domains\Inventory\Domain\Exception\StockBatchExpired;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Domains\Inventory\Domain\Model\StockReservation;
use App\Domains\Inventory\Domain\StockBalanceRepository;
use App\Domains\Inventory\Domain\StockReservationRepository;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `ReserveStock` (ADR-180 D-E).
 *
 * Flow: resolve active item + active location → ADR-133 per-facility refinement
 * (`inventory.reservations.manage`) → resolve the batch (must exist for a
 * batch-tracked item) → SELF-HEAL: lock + expire lapsed ACTIVE reservations on
 * the same (item, location, batch) → load the balance `FOR UPDATE` (§303) and
 * release each lapsed hold's `reserved_qty` → `StockBalance::reserve()` (Inv 17 —
 * `InsufficientStock::forReservation` if it would exceed the reservable qty) →
 * `StockReservation::reserve()` (ACTIVE, records `StockReserved`) → save the
 * balance + the reservation (one txn).
 *
 * Lock order is uniform across every reservation handler — the reservation
 * row(s) first, then the balance row — so a concurrent `ReserveStock` self-heal
 * and a `Release` / `Cancel` / `ExpireStockReservation` on the same lapsed row
 * cannot deadlock (§303).
 *
 * `StockReservationRepository` is the first repository parameter so the bus
 * resolves `StockReservationPolicy` (class-level `reserve`).
 */
final class ReserveStockHandler
{
    public function __construct(
        private readonly StockReservationRepository $reservations,
        private readonly StockBalanceRepository $balances,
        private readonly BatchRepository $batches,
        private readonly StockContextResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(ReserveStock $command): void
    {
        $item = $this->resolver->resolveActiveItem($command->itemId);
        $location = $this->resolver->resolveActiveLocation($command->locationId);

        $this->resolver->assertActorScopedToFacility(
            $location->facilityId,
            'inventory.reservations.manage',
            $command->reservationId,
        );

        $tenantId = $this->context->tenantId();

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

        // Self-heal (ADR-180 D-E) — a lapsed hold must not understate `available`.
        // Lock the lapsed reservation rows BEFORE the balance row so every
        // reservation handler takes locks in the one order (reservation → balance).
        $lapsedReservations = $this->reservations->lapsedForTuple($item->itemId, $location->locationId, $batchId);

        $balance = $this->balances->forUpdateOrCreate(
            $tenantId,
            $location->facilityId,
            $item->itemId,
            $location->locationId,
            $batchId,
        );

        foreach ($lapsedReservations as $lapsed) {
            $balance->release((string) $lapsed->reserved_qty);
            $lapsed->expire();
            $this->reservations->save($lapsed);
        }

        $balance->reserve($command->quantity);

        $reservation = StockReservation::reserve(
            id: $command->reservationId,
            tenantId: $tenantId,
            facilityId: $location->facilityId,
            itemId: $item->itemId,
            locationId: $location->locationId,
            batchId: $batchId,
            quantity: $command->quantity,
            consumerType: strtoupper(trim($command->consumerType)),
            consumerRef: $command->consumerRef,
            reason: $command->reason,
            holdUntil: $command->holdUntil !== null ? CarbonImmutable::parse($command->holdUntil) : null,
            requestedByUserId: $command->actorId,
        );

        $this->balances->save($balance);
        $this->reservations->save($reservation);
    }
}
