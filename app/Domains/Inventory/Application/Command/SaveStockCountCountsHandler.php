<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Application\StockContextResolver;
use App\Domains\Inventory\Domain\Model\StockCountLine;
use App\Domains\Inventory\Domain\StockCountRepository;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `SaveStockCountCounts` (Phase 8, stock counts).
 *
 * Flow: load the count `FOR UPDATE` → ADR-133 per-facility refinement
 * (`inventory.counts.manage`) → the count must still be open → write the
 * counted quantity on each named line. A line that is not on this sheet is
 * ignored (a stale form after a reload is harmless). Lines are plain rows, not
 * aggregates, so they are saved directly.
 *
 * `StockCountRepository` is the first repository parameter so the bus resolves
 * `StockCountPolicy` from it.
 */
final class SaveStockCountCountsHandler
{
    public function __construct(
        private readonly StockCountRepository $counts,
        private readonly StockContextResolver $resolver,
    ) {}

    public function __invoke(SaveStockCountCounts $command): void
    {
        $count = $this->counts->getByIdForUpdate($command->stockCountId);

        $this->resolver->assertActorScopedToFacility(
            (string) $count->facility_id,
            'inventory.counts.manage',
            $command->stockCountId,
        );

        $count->assertOpen();

        if ($command->counts === []) {
            return;
        }

        $now = CarbonImmutable::now();

        $lines = StockCountLine::query()
            ->where('stock_count_id', $command->stockCountId)
            ->whereIn('id', array_keys($command->counts))
            ->get()
            ->keyBy('id');

        foreach ($command->counts as $lineId => $quantity) {
            $line = $lines->get($lineId);

            if (! $line instanceof StockCountLine) {
                continue;
            }

            $line->counted_qty = $quantity;               // null clears it back to "not counted"
            $line->counted_by_user_id = $quantity !== null ? $command->actorId : null;
            $line->counted_at = $quantity !== null ? $now : null;
            $line->save();
        }
    }
}
