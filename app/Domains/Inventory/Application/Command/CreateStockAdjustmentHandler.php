<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Application\StockContextResolver;
use App\Domains\Inventory\Domain\BatchRepository;
use App\Domains\Inventory\Domain\Exception\ControlledStockWitnessRequired;
use App\Domains\Inventory\Domain\Exception\InventoryItemNotStockable;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Domains\Inventory\Domain\Model\StockAdjustment;
use App\Domains\Inventory\Domain\StockAdjustmentRepository;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `CreateStockAdjustment` (ADR-170 D-H).
 *
 * Flow: resolve active item + active location (→ facility) → ADR-133
 * per-facility refinement (`inventory.adjustments.create`) → resolve the batch
 * (must already exist for a batch-tracked item) → `StockAdjustment::raise`
 * (PENDING_APPROVAL) → versioned save (no event — the balance emits
 * `StockAdjusted` on approval).
 *
 * `StockAdjustmentRepository` is the first repository parameter so the bus
 * resolves `StockAdjustmentPolicy`.
 */
final class CreateStockAdjustmentHandler
{
    public function __construct(
        private readonly StockAdjustmentRepository $adjustments,
        private readonly BatchRepository $batches,
        private readonly StockContextResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreateStockAdjustment $command): void
    {
        $item = $this->resolver->resolveActiveItem($command->itemId);
        $location = $this->resolver->resolveActiveLocation($command->locationId);

        $this->resolver->assertActorScopedToFacility(
            $location->facilityId,
            'inventory.adjustments.create',
            $command->adjustmentId,
        );

        // Controlled medicine: anything LEAVING stock needs an independent
        // witness recorded with the request (Phase 0 disposition policy).
        $witnessName = $command->witnessName !== null && trim($command->witnessName) !== ''
            ? trim($command->witnessName)
            : null;

        if ($item->isControlled
            && strtoupper(trim($command->direction)) === StockAdjustment::DIRECTION_DECREASE
            && $witnessName === null) {
            throw ControlledStockWitnessRequired::forItem($item->code);
        }

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
            $batchId = (string) $batch->id;
        }

        $adjustment = StockAdjustment::raise(
            id: $command->adjustmentId,
            tenantId: $tenantId,
            facilityId: $location->facilityId,
            itemId: $item->itemId,
            locationId: $location->locationId,
            batchId: $batchId,
            direction: $command->direction,
            quantity: $command->quantity,
            reasonCode: $command->reasonCode,
            reasonText: $command->reasonText,
            requestedByUserId: $command->actorId,
            witnessName: $witnessName,
            requestedAt: CarbonImmutable::now(),
        );

        $this->adjustments->save($adjustment);
    }
}
