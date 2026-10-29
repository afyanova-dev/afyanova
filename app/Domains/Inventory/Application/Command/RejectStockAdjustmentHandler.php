<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Application\StockContextResolver;
use App\Domains\Inventory\Domain\Service\StockAdjustmentLifecycle;
use App\Domains\Inventory\Domain\StockAdjustmentRepository;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `RejectStockAdjustment` (ADR-170 D-H).
 *
 * Flow: load the adjustment `FOR UPDATE` → ADR-133 per-facility refinement
 * (`inventory.adjustments.approve`) → `StockAdjustmentLifecycle` validates
 * PENDING_APPROVAL → record the rejection → versioned save (no stock change,
 * no event).
 */
final class RejectStockAdjustmentHandler
{
    public function __construct(
        private readonly StockAdjustmentRepository $adjustments,
        private readonly StockContextResolver $resolver,
        private readonly StockAdjustmentLifecycle $lifecycle,
    ) {}

    public function __invoke(RejectStockAdjustment $command): void
    {
        $adjustment = $this->adjustments->getByIdForUpdate($command->adjustmentId);

        $this->resolver->assertActorScopedToFacility(
            (string) $adjustment->facility_id,
            'inventory.adjustments.approve',
            $command->adjustmentId,
        );

        $this->lifecycle->assertCanReject($adjustment->status);

        $adjustment->markRejected($command->actorId, $command->reason, CarbonImmutable::now());

        $this->adjustments->save($adjustment);
    }
}
