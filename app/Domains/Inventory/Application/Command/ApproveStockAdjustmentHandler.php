<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Application\StockContextResolver;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Domains\Inventory\Domain\Service\StockAdjustmentLifecycle;
use App\Domains\Inventory\Domain\Service\StockLedgerService;
use App\Domains\Inventory\Domain\StockAdjustmentRepository;
use App\Domains\Inventory\Domain\StockBalanceRepository;
use App\Domains\Inventory\Infrastructure\Sequence\MovementNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Orchestrates `ApproveStockAdjustment` (ADR-170 D-H / D-I).
 *
 * Flow: load the adjustment `FOR UPDATE` → ADR-133 per-facility refinement
 * (`inventory.adjustments.approve`) → `StockAdjustmentLifecycle` validates
 * PENDING_APPROVAL + not self-approval → resolve the item (for its `uom`) →
 * allocate `MOV-%08d` → append the immutable ADJUSTMENT movement (direction per
 * the adjustment) → record the approval on the adjustment row → load the
 * balance `FOR UPDATE` → `StockLedgerService::postAdjustment` (guards Inv 5 on a
 * DECREASE, records `StockAdjusted`) → save the adjustment + the balance (one
 * txn).
 */
final class ApproveStockAdjustmentHandler
{
    public function __construct(
        private readonly StockAdjustmentRepository $adjustments,
        private readonly StockBalanceRepository $balances,
        private readonly StockContextResolver $resolver,
        private readonly StockAdjustmentLifecycle $lifecycle,
        private readonly StockLedgerService $ledger,
        private readonly MovementNumberSequence $movementNumbers,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(ApproveStockAdjustment $command): void
    {
        $adjustment = $this->adjustments->getByIdForUpdate($command->adjustmentId);

        $this->resolver->assertActorScopedToFacility(
            (string) $adjustment->facility_id,
            'inventory.adjustments.approve',
            $command->adjustmentId,
        );

        $this->lifecycle->assertCanApprove($adjustment->status);

        $this->lifecycle->assertNotSelfApproval(
            $command->adjustmentId,
            (string) $adjustment->requested_by_user_id,
            $command->actorId,
        );

        $item = $this->resolver->resolveActiveItem((string) $adjustment->item_id);

        $tenantId = $this->context->tenantId();
        $now = CarbonImmutable::now();

        $movement = StockMovement::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => (string) $adjustment->facility_id,
            'movement_number' => $this->movementNumbers->next($tenantId),
            'movement_type' => StockMovement::TYPE_ADJUSTMENT,
            'direction' => $adjustment->movementDirection(),
            'quantity' => (string) $adjustment->quantity,
            'uom' => $item->uom,
            'item_id' => (string) $adjustment->item_id,
            'location_id' => (string) $adjustment->location_id,
            'batch_id' => $adjustment->batch_id !== null ? (string) $adjustment->batch_id : null,
            'source_type' => 'ADJUSTMENT',
            'source_ref' => $command->adjustmentId,
            'po_ref' => null,
            'reason_text' => $adjustment->reason_text,
            'actor_user_id' => $command->actorId,
            'occurred_at' => $now,
            'recorded_at' => $now,
            'version' => 0,
        ]);

        $adjustment->markApproved($command->actorId, (string) $movement->id, $now);

        $balance = $this->balances->forUpdateOrCreate(
            $tenantId,
            (string) $adjustment->facility_id,
            (string) $adjustment->item_id,
            (string) $adjustment->location_id,
            $adjustment->batch_id !== null ? (string) $adjustment->batch_id : null,
        );

        $this->ledger->postAdjustment($balance, $movement, $adjustment);

        $this->adjustments->save($adjustment);
        $this->balances->save($balance);
    }
}
