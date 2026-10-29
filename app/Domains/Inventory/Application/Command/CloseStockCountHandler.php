<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Application\StockContextResolver;
use App\Domains\Inventory\Domain\BatchRepository;
use App\Domains\Inventory\Domain\Model\StockAdjustment;
use App\Domains\Inventory\Domain\Model\StockCountLine;
use App\Domains\Inventory\Domain\Quantity;
use App\Domains\Inventory\Domain\StockBalanceReader;
use App\Domains\Inventory\Domain\StockCountRepository;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Orchestrates `CloseStockCount` (Phase 8, stock counts).
 *
 * Discarding just freezes the sheet and raises nothing.
 *
 * Completing freezes the sheet AND turns each difference into a request for a
 * second person to approve:
 *   - one `CreateStockAdjustment` per counted line whose quantity disagrees
 *     with the snapshot, `reason_code = COUNT_VARIANCE`, direction from the
 *     sign (found more → INCREASE, found less → DECREASE);
 *   - the actor is the requester, so the existing self-approval guard stops
 *     them approving their own corrections — counting and correcting stay two
 *     jobs done by two people;
 *   - a difference is NOT raised when the on-hand quantity has moved since the
 *     snapshot (a receipt, dispense or transfer while the count was open), so
 *     the sheet does not attribute someone else's movement to the counter. The
 *     line is marked `SKIPPED_STOCK_MOVED` and the screen says so;
 *   - a difference is also not raised when the actor may not create stock
 *     adjustments; the line is marked `SKIPPED_NEEDS_PERMISSION`, and the
 *     count still completes honestly (blocking the physical record would be
 *     worse than leaving the correction for someone else);
 *   - a controlled medicine shortfall still needs the witness name — the nested
 *     command enforces it, so the whole completion is refused (and rolled back)
 *     until one is supplied.
 *
 * The nested dispatches run inside this command's transaction (`Tenancy::run`
 * uses a savepoint), so a refused correction leaves the count open and nothing
 * half-written. The count itself never edits a balance.
 *
 * `StockCountRepository` is the first repository parameter so the bus resolves
 * `StockCountPolicy` from it.
 */
final class CloseStockCountHandler
{
    public function __construct(
        private readonly StockCountRepository $counts,
        private readonly StockBalanceReader $balances,
        private readonly BatchRepository $batches,
        private readonly StockContextResolver $resolver,
        private readonly CommandBus $commands,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CloseStockCount $command): CloseStockCountResult
    {
        $count = $this->counts->getByIdForUpdate($command->stockCountId);

        $this->resolver->assertActorScopedToFacility(
            (string) $count->facility_id,
            'inventory.counts.manage',
            $command->stockCountId,
        );

        $now = CarbonImmutable::now();

        if (! $command->keep()) {
            $count->discard($now);
            $this->counts->save($count);

            return new CloseStockCountResult(completed: false, adjustmentsRaised: 0, linesSkipped: 0);
        }

        if ($command->counts !== []) {
            $countLines = StockCountLine::query()
                ->where('stock_count_id', $command->stockCountId)
                ->whereIn('id', array_keys($command->counts))
                ->get()
                ->keyBy('id');

            foreach ($command->counts as $lineId => $quantity) {
                $line = $countLines->get($lineId);
                if (! $line instanceof StockCountLine) {
                    continue;
                }

                $line->counted_qty = $quantity;
                $line->counted_by_user_id = $quantity !== null ? $command->actorId : null;
                $line->counted_at = $quantity !== null ? $now : null;
                $line->save();
            }
        }

        $count->complete($now);

        $facilityId = (string) $count->facility_id;
        $locationId = (string) $count->location_id;
        $tenantId = $this->context->tenantId();
        $mayRaiseCorrections = $this->resolver->actorHasPermission($facilityId, 'inventory.adjustments.create');

        $raised = 0;
        $skipped = 0;

        $lines = StockCountLine::query()
            ->where('stock_count_id', $command->stockCountId)
            ->orderBy('id')
            ->get();

        foreach ($lines as $line) {
            if (! $line->hasVariance()) {
                continue;
            }

            if (! $mayRaiseCorrections) {
                $line->variance_skipped_reason = StockCountLine::SKIPPED_NEEDS_PERMISSION;
                $line->save();
                $skipped++;

                continue;
            }

            // The count is only trustworthy if the record has not moved since
            // the snapshot. If it has, the difference belongs to a receipt,
            // dispense or transfer, not to the counter — leave it for a person.
            $current = $this->balances->onHandTotal(
                (string) $line->item_id,
                $locationId,
                $line->batch_id !== null ? (string) $line->batch_id : null,
            );

            if (Quantity::compare($current, (string) $line->system_qty) !== 0) {
                $line->variance_skipped_reason = StockCountLine::SKIPPED_STOCK_MOVED;
                $line->save();
                $skipped++;

                continue;
            }

            $decrease = Quantity::isNegative((string) $line->variance());
            $adjustmentId = (string) Str::uuid7();

            $this->commands->dispatch(new CreateStockAdjustment(
                adjustmentId: $adjustmentId,
                actorId: $command->actorId,
                itemId: (string) $line->item_id,
                locationId: $locationId,
                direction: $decrease ? StockAdjustment::DIRECTION_DECREASE : StockAdjustment::DIRECTION_INCREASE,
                quantity: (string) $line->varianceQuantity(),
                reasonCode: 'COUNT_VARIANCE',
                reasonText: sprintf(
                    'Stock count %s: counted %s against %s on the sheet.',
                    (string) $count->count_number,
                    (string) $line->counted_qty,
                    (string) $line->system_qty,
                ),
                witnessName: $decrease ? $command->witnessName : null,
                batchNumber: $this->batchNumberFor($line),
                idempotencyKey: null,
            ));

            $line->variance_adjustment_id = $adjustmentId;
            $line->variance_skipped_reason = null;
            $line->save();

            $raised++;
        }

        $this->counts->save($count);

        return new CloseStockCountResult(completed: true, adjustmentsRaised: $raised, linesSkipped: $skipped);
    }

    private function batchNumberFor(StockCountLine $line): ?string
    {
        if ($line->batch_id === null) {
            return null;
        }

        return (string) $this->batches->getById((string) $line->batch_id)->batch_number;
    }
}
