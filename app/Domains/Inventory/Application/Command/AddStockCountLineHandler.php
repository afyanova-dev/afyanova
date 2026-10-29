<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Application\StockContextResolver;
use App\Domains\Inventory\Domain\BatchRepository;
use App\Domains\Inventory\Domain\Exception\InventoryItemNotStockable;
use App\Domains\Inventory\Domain\Exception\StockCountNotAllowed;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Domains\Inventory\Domain\Model\StockCountLine;
use App\Domains\Inventory\Domain\StockBalanceReader;
use App\Domains\Inventory\Domain\StockCountRepository;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Orchestrates `AddStockCountLine` (Phase 8, stock counts).
 *
 * Flow: load the count `FOR UPDATE` → ADR-133 per-facility refinement
 * (`inventory.counts.manage`) → the count must still be open → resolve the
 * active item → resolve the batch for a batch-tracked item (it must already
 * exist) → refuse a duplicate line → add the line with the current on-hand as
 * its snapshot.
 *
 * `StockCountRepository` is the first repository parameter so the bus resolves
 * `StockCountPolicy` from it.
 */
final class AddStockCountLineHandler
{
    public function __construct(
        private readonly StockCountRepository $counts,
        private readonly BatchRepository $batches,
        private readonly StockBalanceReader $balances,
        private readonly StockContextResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(AddStockCountLine $command): void
    {
        $count = $this->counts->getByIdForUpdate($command->stockCountId);

        $this->resolver->assertActorScopedToFacility(
            (string) $count->facility_id,
            'inventory.counts.manage',
            $command->stockCountId,
        );

        $count->assertOpen();

        $item = $this->resolver->resolveActiveItem($command->itemId);

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

        $exists = StockCountLine::query()
            ->where('stock_count_id', $command->stockCountId)
            ->where('item_id', $command->itemId)
            ->when(
                $batchId === null,
                static fn ($query) => $query->whereNull('batch_id'),
                static fn ($query) => $query->where('batch_id', $batchId),
            )
            ->exists();

        if ($exists) {
            throw StockCountNotAllowed::lineAlreadyOnSheet($command->itemId);
        }

        StockCountLine::query()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => (string) $count->facility_id,
            'stock_count_id' => $command->stockCountId,
            'item_id' => $item->itemId,
            'batch_id' => $batchId,
            'system_qty' => $this->balances->onHandTotal($item->itemId, (string) $count->location_id, $batchId),
            'counted_qty' => null,
        ]);
    }
}
