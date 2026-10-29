<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Reader;

use App\Domains\Inventory\Contracts\Dto\AvailableStockSummary;
use App\Domains\Inventory\Contracts\Dto\ExpiringStockSummary;
use App\Domains\Inventory\Contracts\Dto\LocationItemStockSummary;
use App\Domains\Inventory\Contracts\Dto\LowStockItemSummary;
use App\Domains\Inventory\Contracts\Dto\StockBalanceSummary;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Domains\Inventory\Domain\Model\Item;
use App\Domains\Inventory\Domain\Model\ItemLevel;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockLocation;
use App\Domains\Inventory\Domain\Quantity;
use App\Domains\Inventory\Domain\StockBalanceReader;
use App\Shared\Domain\NotFound;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class EloquentStockBalanceReader implements StockBalanceReader
{
    public function forItemLocation(string $itemId, string $locationId): array
    {
        $rows = StockBalance::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->orderBy('batch_id')
            ->get();

        return $this->summariesFor($rows);
    }

    public function forLocation(
        string $locationId,
        int $page,
        int $perPage,
        string $sortBy,
        string $sortDirection,
        string $search,
    ): LengthAwarePaginator {
        $searchLike = '%'.mb_strtolower($search).'%';
        $aggregate = StockBalance::query()
            ->join('inv_items', 'inv_items.id', '=', 'inv_stock_balances.item_id')
            ->where('location_id', $locationId)
            ->where(static fn (Builder $query): Builder => $query
                ->where('on_hand_qty', '<>', 0)
                ->orWhere('reserved_qty', '<>', 0))
            ->when($search !== '', static fn (Builder $query): Builder => $query
                ->where(static fn (Builder $query): Builder => $query
                    ->whereRaw('LOWER(inv_items.code) LIKE ?', [$searchLike])
                    ->orWhereRaw('LOWER(inv_items.name) LIKE ?', [$searchLike])))
            ->select([
                'inv_items.id as item_id',
                'inv_items.code as item_code',
                'inv_items.name as item_name',
                'inv_items.uom as uom',
            ])
            ->selectRaw('SUM(inv_stock_balances.on_hand_qty) as on_hand_qty')
            ->selectRaw('SUM(inv_stock_balances.reserved_qty) as reserved_qty')
            ->selectRaw('SUM(inv_stock_balances.on_hand_qty) - SUM(inv_stock_balances.reserved_qty) as available_qty')
            ->groupBy('inv_items.id', 'inv_items.code', 'inv_items.name', 'inv_items.uom');

        $sortColumn = match ($sortBy) {
            'name' => 'inv_items.name',
            'on_hand' => 'on_hand_qty',
            'reserved' => 'reserved_qty',
            'available' => 'available_qty',
            default => 'inv_items.code',
        };

        /** @var LengthAwarePaginator<int, object> $pageOfTotals */
        $pageOfTotals = $aggregate
            ->orderBy($sortColumn, $sortDirection)
            ->orderBy('inv_items.id')
            ->paginate($perPage, ['*'], 'page', $page);
        $totals = $pageOfTotals->getCollection();
        $itemIds = $totals->pluck('item_id')->all();

        $batchBalances = $itemIds === []
            ? []
            : $this->summariesFor(StockBalance::query()
                ->where('location_id', $locationId)
                ->whereIn('item_id', $itemIds)
                ->where(static fn (Builder $query): Builder => $query
                    ->where('on_hand_qty', '<>', 0)
                    ->orWhere('reserved_qty', '<>', 0))
                ->orderBy('item_id')
                ->orderBy('batch_id')
                ->get());
        $batchesByItem = [];
        foreach ($batchBalances as $balance) {
            $batchesByItem[$balance->itemId][] = $balance;
        }

        $pageOfTotals->setCollection($totals->map(fn (object $row): LocationItemStockSummary => new LocationItemStockSummary(
            itemId: (string) $row->item_id,
            itemCode: (string) $row->item_code,
            itemName: (string) $row->item_name,
            onHandQty: Quantity::of((string) $row->on_hand_qty),
            reservedQty: Quantity::of((string) $row->reserved_qty),
            availableQty: Quantity::sub((string) $row->on_hand_qty, (string) $row->reserved_qty),
            uom: (string) $row->uom,
            batches: $batchesByItem[(string) $row->item_id] ?? [],
        )));

        return $pageOfTotals;
    }

    public function stockAtLocation(string $locationId): array
    {
        $totals = StockBalance::query()
            ->join('inv_items', 'inv_items.id', '=', 'inv_stock_balances.item_id')
            ->where('location_id', $locationId)
            ->where(static fn (Builder $query): Builder => $query
                ->where('on_hand_qty', '<>', 0)
                ->orWhere('reserved_qty', '<>', 0))
            ->select([
                'inv_items.id as item_id',
                'inv_items.code as item_code',
                'inv_items.name as item_name',
                'inv_items.uom as uom',
            ])
            ->selectRaw('SUM(inv_stock_balances.on_hand_qty) as on_hand_qty')
            ->selectRaw('SUM(inv_stock_balances.reserved_qty) as reserved_qty')
            ->groupBy('inv_items.id', 'inv_items.code', 'inv_items.name', 'inv_items.uom')
            ->orderBy('inv_items.code')
            ->get();

        $itemIds = $totals->pluck('item_id')->all();

        $batchBalances = $itemIds === []
            ? []
            : $this->summariesFor(StockBalance::query()
                ->where('location_id', $locationId)
                ->whereIn('item_id', $itemIds)
                ->where(static fn (Builder $query): Builder => $query
                    ->where('on_hand_qty', '<>', 0)
                    ->orWhere('reserved_qty', '<>', 0))
                ->orderBy('item_id')
                ->orderBy('batch_id')
                ->get());
        $batchesByItem = [];
        foreach ($batchBalances as $balance) {
            $batchesByItem[$balance->itemId][] = $balance;
        }

        return array_values($totals->map(function (object $row) use ($batchesByItem): LocationItemStockSummary {
            /** @var array<string, mixed> $data */
            $data = $row->toArray();

            return new LocationItemStockSummary(
                itemId: (string) $data['item_id'],
                itemCode: (string) $data['item_code'],
                itemName: (string) $data['item_name'],
                onHandQty: Quantity::of((string) $data['on_hand_qty']),
                reservedQty: Quantity::of((string) $data['reserved_qty']),
                availableQty: Quantity::sub((string) $data['on_hand_qty'], (string) $data['reserved_qty']),
                uom: (string) $data['uom'],
                batches: $batchesByItem[(string) $data['item_id']] ?? [],
            );
        })->all());
    }

    public function forFacility(
        string $facilityId,
        int $page,
        int $perPage,
        string $sortBy,
        string $sortDirection,
        string $search,
        ?string $locationId = null,
        ?array $departmentIds = null,
    ): LengthAwarePaginator {
        $searchLike = '%'.mb_strtolower($search).'%';

        $sortColumn = match ($sortBy) {
            'location' => 'inv_stock_locations.code',
            'on_hand' => 'inv_stock_balances.on_hand_qty',
            'reserved' => 'inv_stock_balances.reserved_qty',
            'available' => 'inv_stock_balances.on_hand_qty - inv_stock_balances.reserved_qty',
            default => 'inv_items.code',
        };

        /** @var LengthAwarePaginator<int, StockBalance> $paginated */
        $paginated = StockBalance::query()
            ->select('inv_stock_balances.*')
            ->join('inv_items', 'inv_items.id', '=', 'inv_stock_balances.item_id')
            ->join('inv_stock_locations', 'inv_stock_locations.id', '=', 'inv_stock_balances.location_id')
            ->where('inv_stock_balances.facility_id', $facilityId)
            ->when($locationId !== null, static fn (Builder $query): Builder => $query
                ->where('inv_stock_balances.location_id', $locationId))
            ->when($departmentIds !== null, static fn (Builder $query): Builder => $query
                ->whereIn('inv_stock_locations.department_id', $departmentIds))
            ->where(static fn (Builder $query): Builder => $query
                ->where('inv_stock_balances.on_hand_qty', '<>', 0)
                ->orWhere('inv_stock_balances.reserved_qty', '<>', 0))
            ->when($search !== '', static fn (Builder $query): Builder => $query
                ->where(static fn (Builder $query): Builder => $query
                    ->whereRaw('LOWER(inv_items.code) LIKE ?', [$searchLike])
                    ->orWhereRaw('LOWER(inv_items.name) LIKE ?', [$searchLike])
                    ->orWhereRaw('LOWER(inv_stock_locations.code) LIKE ?', [$searchLike])
                    ->orWhereRaw('LOWER(inv_stock_locations.name) LIKE ?', [$searchLike])))
            ->orderBy($sortColumn, $sortDirection)
            ->orderBy('inv_stock_balances.item_id')
            ->orderBy('inv_stock_balances.location_id')
            ->orderBy('inv_stock_balances.batch_id')
            ->paginate($perPage, ['*'], 'page', $page);

        $paginated->setCollection(collect($this->summariesFor(collect($paginated->items()))));

        return $paginated;
    }

    public function expiringForFacility(string $facilityId, int $windowDays): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $cutoff = $today->addDays($windowDays);

        /** @var Collection<int, StockBalance> $rows */
        $rows = StockBalance::query()
            ->select('inv_stock_balances.*')
            ->join('inv_batches', 'inv_batches.id', '=', 'inv_stock_balances.batch_id')
            ->where('inv_stock_balances.facility_id', $facilityId)
            ->where('inv_stock_balances.on_hand_qty', '>', 0)
            ->whereNotNull('inv_batches.expiry_date')
            ->where('inv_batches.expiry_date', '<=', $cutoff->toDateString())
            ->orderBy('inv_batches.expiry_date')
            ->orderBy('inv_stock_balances.item_id')
            ->orderBy('inv_stock_balances.batch_id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        /** @var array<string, Item> $items */
        $items = Item::query()
            ->whereIn('id', $rows->pluck('item_id')->unique()->all())
            ->get()->keyBy('id')->all();

        /** @var array<string, StockLocation> $locations */
        $locations = StockLocation::query()
            ->whereIn('id', $rows->pluck('location_id')->unique()->all())
            ->get()->keyBy('id')->all();

        /** @var array<string, Batch> $batches */
        $batches = Batch::query()
            ->whereIn('id', $rows->pluck('batch_id')->filter()->unique()->all())
            ->get()->keyBy('id')->all();

        $out = [];

        foreach ($rows as $row) {
            $batch = $batches[(string) $row->batch_id] ?? null;

            if ($batch === null || $batch->expiry_date === null) {
                continue;
            }

            $item = $items[(string) $row->item_id] ?? null;
            $location = $locations[(string) $row->location_id] ?? null;
            $expiry = $batch->expiry_date->startOfDay();

            $out[] = new ExpiringStockSummary(
                balanceId: (string) $row->id,
                itemId: (string) $row->item_id,
                itemCode: $item !== null ? (string) $item->code : '',
                itemName: $item !== null ? (string) $item->name : '',
                locationId: (string) $row->location_id,
                locationCode: $location !== null ? (string) $location->code : '',
                batchId: (string) $batch->id,
                batchNumber: (string) $batch->batch_number,
                expiryDate: $expiry->toDateString(),
                daysRemaining: (int) round($today->diffInDays($expiry, false)),
                onHandQty: Quantity::of((string) $row->on_hand_qty),
                uom: $item !== null ? (string) $item->uom : '',
                expired: $batch->isExpired($today),
                controlled: $item !== null && (bool) $item->is_controlled,
            );
        }

        return $out;
    }

    public function lowStockForFacility(string $facilityId): array
    {
        $asOf = CarbonImmutable::now();

        /** @var Collection<int, ItemLevel> $levels */
        $levels = ItemLevel::query()
            ->where('facility_id', $facilityId)
            ->get();

        if ($levels->isEmpty()) {
            return [];
        }

        /** @var array<string, Item> $items */
        $items = Item::query()
            ->whereIn('id', $levels->pluck('item_id')->unique()->all())
            ->where('active', true)
            ->get()
            ->keyBy('id')
            ->all();

        if ($items === []) {
            return [];
        }

        /** @var Collection<int, StockBalance> $balances */
        $balances = StockBalance::query()
            ->where('facility_id', $facilityId)
            ->whereIn('item_id', array_keys($items))
            ->where('on_hand_qty', '>', 0)
            ->get();

        $batchIds = $balances->pluck('batch_id')->filter()->unique()->all();
        /** @var array<string, Batch> $batches */
        $batches = $batchIds === []
            ? []
            : Batch::query()->whereIn('id', $batchIds)->get()->keyBy('id')->all();

        /** @var array<string, string> $usable */
        $usable = [];
        /** @var array<string, string> $expired */
        $expired = [];
        foreach ($balances as $balance) {
            $itemId = (string) $balance->item_id;
            $batch = $balance->batch_id !== null ? ($batches[(string) $balance->batch_id] ?? null) : null;
            $quantity = Quantity::of((string) $balance->on_hand_qty);

            if ($batch !== null && $batch->isExpired($asOf)) {
                $expired[$itemId] = Quantity::add($expired[$itemId] ?? '0', $quantity);

                continue;
            }

            $usable[$itemId] = Quantity::add($usable[$itemId] ?? '0', $quantity);
        }

        $out = [];
        foreach ($levels as $level) {
            $itemId = (string) $level->item_id;
            $item = $items[$itemId] ?? null;

            // A level on a retired item is kept (it applies again if the item
            // is reactivated) but is not chased while the item is retired.
            if ($item === null) {
                continue;
            }

            $onHand = $usable[$itemId] ?? '0.0000';
            $minimum = Quantity::of((string) $level->min_level);

            // The minimum is the reorder point: reaching it is already "running
            // low", so equality counts.
            if (Quantity::compare($onHand, $minimum) > 0) {
                continue;
            }

            $out[] = new LowStockItemSummary(
                itemId: $itemId,
                itemCode: (string) $item->code,
                itemName: (string) $item->name,
                uom: (string) $item->uom,
                packUnit: $item->pack_unit !== null ? (string) $item->pack_unit : null,
                packSize: $item->pack_size !== null ? Quantity::of((string) $item->pack_size) : null,
                minLevel: $minimum,
                onHandQty: $onHand,
                expiredQty: $expired[$itemId] ?? '0.0000',
                shortfall: Quantity::sub($minimum, $onHand),
                outOfStock: Quantity::compare($onHand, '0') <= 0,
            );
        }

        usort($out, static function (LowStockItemSummary $a, LowStockItemSummary $b): int {
            if ($a->outOfStock !== $b->outOfStock) {
                return $a->outOfStock ? -1 : 1;
            }

            return Quantity::compare($b->shortfall, $a->shortfall)
                ?: strcmp($a->itemCode, $b->itemCode);
        });

        return $out;
    }

    public function availableForItem(
        string $itemId,
        string $facilityId,
        ?array $departmentIds = null,
    ): AvailableStockSummary
    {
        $item = Item::query()->whereKey($itemId)->first()
            ?? throw NotFound::aggregate(Item::class, $itemId);

        $rows = StockBalance::query()
            ->where('item_id', $itemId)
            ->where('facility_id', $facilityId)
            ->when($departmentIds !== null, static fn (Builder $query): Builder => $query
                ->whereIn('location_id', StockLocation::query()
                    ->select('id')
                    ->where('facility_id', $facilityId)
                    ->whereIn('department_id', $departmentIds)))
            ->orderBy('location_id')
            ->orderBy('batch_id')
            ->get();

        // Phase 4 expiry exclusion: totals sum ELIGIBLE rows only, so expired
        // stock is never reported as available. `byLocation` still lists every
        // batch (flagged), so the physical picture is not hidden.
        $byLocation = $this->summariesFor($rows);

        $onHand = '0';
        $reserved = '0';
        foreach ($byLocation as $balance) {
            if (! $balance->eligible) {
                continue;
            }
            $onHand = Quantity::add($onHand, $balance->onHandQty);
            $reserved = Quantity::add($reserved, $balance->reservedQty);
        }

        return new AvailableStockSummary(
            itemId: $itemId,
            itemCode: (string) $item->code,
            facilityId: $facilityId,
            uom: (string) $item->uom,
            totalOnHandQty: $onHand,
            totalReservedQty: $reserved,
            totalAvailableQty: Quantity::sub($onHand, $reserved),
            byLocation: $byLocation,
        );
    }

    public function onHandTotal(string $itemId, string $locationId, ?string $batchId): string
    {
        $row = StockBalance::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->when(
                $batchId === null,
                static fn (Builder $q): Builder => $q->whereNull('batch_id'),
                static fn (Builder $q): Builder => $q->where('batch_id', $batchId),
            )
            ->first();

        return $row !== null ? Quantity::of((string) $row->on_hand_qty) : '0.0000';
    }

    /**
     * @param  Collection<int, StockBalance>  $rows
     * @return list<StockBalanceSummary>
     */
    private function summariesFor(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $asOf = CarbonImmutable::now();

        /** @var array<string, Item> $items */
        $items = Item::query()
            ->whereIn('id', $rows->pluck('item_id')->unique()->all())
            ->get()->keyBy('id')->all();

        /** @var array<string, StockLocation> $locations */
        $locations = StockLocation::query()
            ->whereIn('id', $rows->pluck('location_id')->unique()->all())
            ->get()->keyBy('id')->all();

        $batchIds = $rows->pluck('batch_id')->filter()->unique()->all();
        /** @var array<string, Batch> $batches */
        $batches = $batchIds === []
            ? []
            : Batch::query()->whereIn('id', $batchIds)->get()->keyBy('id')->all();

        $out = [];
        foreach ($rows as $row) {
            $item = $items[(string) $row->item_id] ?? null;
            $location = $locations[(string) $row->location_id] ?? null;
            $batch = $row->batch_id !== null ? ($batches[(string) $row->batch_id] ?? null) : null;

            $out[] = new StockBalanceSummary(
                balanceId: (string) $row->id,
                itemId: (string) $row->item_id,
                itemCode: $item !== null ? (string) $item->code : '',
                itemName: $item !== null ? (string) $item->name : '',
                facilityId: (string) $row->facility_id,
                locationId: (string) $row->location_id,
                locationCode: $location !== null ? (string) $location->code : '',
                batchId: $row->batch_id !== null ? (string) $row->batch_id : null,
                batchNumber: $batch !== null ? (string) $batch->batch_number : null,
                expiryDate: $batch?->expiry_date?->toDateString(),
                onHandQty: Quantity::of((string) $row->on_hand_qty),
                reservedQty: Quantity::of((string) $row->reserved_qty),
                availableQty: Quantity::sub((string) $row->on_hand_qty, (string) $row->reserved_qty),
                uom: $item !== null ? (string) $item->uom : '',
                eligible: $batch === null || ! $batch->isExpired($asOf),
            );
        }

        return $out;
    }
}
