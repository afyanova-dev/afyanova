<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Application\StockContextResolver;
use App\Domains\Inventory\Domain\Exception\StockMovementNotReversible;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Domains\Inventory\Domain\Service\StockLedgerService;
use App\Domains\Inventory\Domain\StockBalanceRepository;
use App\Domains\Inventory\Infrastructure\Sequence\MovementNumberSequence;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Orchestrates `ReverseConsumption` (ADR-185 D-C).
 *
 * Flow: load the original movement (RLS-scoped) → assert it is a CONSUMPTION and
 * not already reversed → ADR-133 per-facility refinement
 * (`inventory.consumption.record` at the movement's facility) → allocate
 * `MOV-%08d` → append the compensating IN movement (`CONSUMPTION_REVERSAL`,
 * `reverses_movement_id` → the original; the unique partial index is the race /
 * idempotency backstop) → load the balance `FOR UPDATE` →
 * `StockLedgerService::postConsumptionReversal` (`on_hand += quantity`, records
 * `StockConsumptionReversed`) → versioned save. One bus transaction.
 *
 * `StockBalanceRepository` is the FIRST parameter so the bus resolves
 * `StockBalancePolicy::consume`.
 */
final class ReverseConsumptionHandler
{
    public function __construct(
        private readonly StockBalanceRepository $balances,
        private readonly StockContextResolver $resolver,
        private readonly StockLedgerService $ledger,
        private readonly MovementNumberSequence $movementNumbers,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(ReverseConsumption $command): void
    {
        $original = StockMovement::query()->whereKey($command->originalMovementId)->first()
            ?? throw NotFound::aggregate(StockMovement::class, $command->originalMovementId);

        if ((string) $original->movement_type !== StockMovement::TYPE_CONSUMPTION) {
            throw StockMovementNotReversible::notConsumption(
                $command->originalMovementId,
                (string) $original->movement_type,
            );
        }

        if (StockMovement::query()->where('reverses_movement_id', $command->originalMovementId)->exists()) {
            throw StockMovementNotReversible::alreadyReversed($command->originalMovementId);
        }

        $this->resolver->assertActorScopedToFacility(
            (string) $original->facility_id,
            'inventory.consumption.record',
            $command->reversalMovementId,
        );

        $tenantId = $this->context->tenantId();
        $now = CarbonImmutable::now();

        $batchId = $original->batch_id !== null ? (string) $original->batch_id : null;

        $movement = new StockMovement([
            'id' => $command->reversalMovementId,
            'tenant_id' => $tenantId,
            'facility_id' => (string) $original->facility_id,
            'movement_number' => $this->movementNumbers->next($tenantId),
            'movement_type' => StockMovement::TYPE_CONSUMPTION_REVERSAL,
            'direction' => StockMovement::DIRECTION_IN,
            'quantity' => (string) $original->quantity,
            'uom' => (string) $original->uom,
            'item_id' => (string) $original->item_id,
            'location_id' => (string) $original->location_id,
            'batch_id' => $batchId,
            'source_type' => 'PHARMACY_REVERSAL',
            'source_ref' => $command->sourceRef !== null && trim($command->sourceRef) !== '' ? trim($command->sourceRef) : null,
            'po_ref' => null,
            'reason_text' => $command->reason !== null && trim($command->reason) !== '' ? trim($command->reason) : null,
            'reverses_movement_id' => $command->originalMovementId,
            'actor_user_id' => $command->actorId,
            'occurred_at' => $now,
            'recorded_at' => $now,
            'version' => 0,
        ]);

        try {
            $movement->save();
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'inv_stock_movements_one_reversal_per_movement')) {
                throw StockMovementNotReversible::alreadyReversed($command->originalMovementId);
            }
            throw $e;
        }

        $balance = $this->balances->forUpdateOrCreate(
            $tenantId,
            (string) $original->facility_id,
            (string) $original->item_id,
            (string) $original->location_id,
            $batchId,
        );

        $this->ledger->postConsumptionReversal($balance, $movement, $command->originalMovementId);

        $this->balances->save($balance);
    }
}
