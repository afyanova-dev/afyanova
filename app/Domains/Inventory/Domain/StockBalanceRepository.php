<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the materialised Stock Balance aggregate (IC §9;
 * ADR-170 D-F / D-I). This is the FIRST repository parameter of every
 * stock-movement handler — the bus resolves `StockBalancePolicy` from it
 * (the ADR-115 handler→policy rule).
 */
interface StockBalanceRepository extends Repository
{
    public function getById(string $id): StockBalance;

    public function getByIdForUpdate(string $id): StockBalance;

    public function hasNonZeroBalanceForItem(string $itemId): bool;

    public function hasNonZeroBalanceForLocation(string $locationId): bool;

    /**
     * Every non-zero balance row at one location — the snapshot a stock count
     * is taken against (Phase 8, stock counts). Ordered by item then batch so
     * the sheet is stable.
     *
     * @return list<StockBalance>
     */
    public function listAtLocation(string $locationId): array;

    /**
     * The balance row for the (item, location, batch) tuple, locked
     * `FOR UPDATE` — creating a zeroed row (idempotently, `ON CONFLICT DO
     * NOTHING` against `inv_stock_balances_identity`) if none exists yet
     * (blocker B1 — §302 / §304 / §307).
     */
    public function forUpdateOrCreate(
        string $tenantId,
        string $facilityId,
        string $itemId,
        string $locationId,
        ?string $batchId,
    ): StockBalance;
}
