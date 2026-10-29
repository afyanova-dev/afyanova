<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Repository;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\StockBalanceRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Eloquent implementation of {@see StockBalanceRepository} (IC §9; ADR-170
 * D-F / D-I).
 */
final class EloquentStockBalanceRepository extends EloquentRepository implements StockBalanceRepository
{
    protected function model(): string
    {
        return StockBalance::class;
    }

    public function getById(string $id): StockBalance
    {
        return StockBalance::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(StockBalance::class, $id);
    }

    public function getByIdForUpdate(string $id): StockBalance
    {
        return StockBalance::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(StockBalance::class, $id);
    }

    public function hasNonZeroBalanceForItem(string $itemId): bool
    {
        return StockBalance::query()
            ->where('item_id', $itemId)
            ->where(static fn (Builder $query): Builder => $query
                ->where('on_hand_qty', '<>', 0)
                ->orWhere('reserved_qty', '<>', 0))
            ->exists();
    }

    public function hasNonZeroBalanceForLocation(string $locationId): bool
    {
        return StockBalance::query()
            ->where('location_id', $locationId)
            ->where(static fn (Builder $query): Builder => $query
                ->where('on_hand_qty', '<>', 0)
                ->orWhere('reserved_qty', '<>', 0))
            ->exists();
    }

    public function listAtLocation(string $locationId): array
    {
        return StockBalance::query()
            ->where('location_id', $locationId)
            ->where(static fn (Builder $query): Builder => $query
                ->where('on_hand_qty', '<>', 0)
                ->orWhere('reserved_qty', '<>', 0))
            ->orderBy('item_id')
            ->orderBy('batch_id')
            ->get()
            ->all();
    }

    public function forUpdateOrCreate(
        string $tenantId,
        string $facilityId,
        string $itemId,
        string $locationId,
        ?string $batchId,
    ): StockBalance {
        $locked = $this->lockedRow($itemId, $locationId, $batchId);

        if ($locked !== null) {
            return $locked;
        }

        // Race-safe first insert (blocker B1): ON CONFLICT DO NOTHING against
        // `inv_stock_balances_identity` (NULLS NOT DISTINCT), then re-select
        // FOR UPDATE — a concurrent first receipt cannot double-insert.
        DB::table('inv_stock_balances')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'item_id' => $itemId,
            'location_id' => $locationId,
            'batch_id' => $batchId,
            'on_hand_qty' => 0,
            'reserved_qty' => 0,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->lockedRow($itemId, $locationId, $batchId)
            ?? throw new RuntimeException('inv_stock_balances row missing after upsert (ADR-170 D-I / blocker B1).');
    }

    private function lockedRow(string $itemId, string $locationId, ?string $batchId): ?StockBalance
    {
        return StockBalance::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->when(
                $batchId === null,
                static fn (Builder $q): Builder => $q->whereNull('batch_id'),
                static fn (Builder $q): Builder => $q->where('batch_id', $batchId),
            )
            ->lockForUpdate()
            ->first();
    }
}
