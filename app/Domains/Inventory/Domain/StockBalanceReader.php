<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Contracts\Dto\AvailableStockSummary;
use App\Domains\Inventory\Contracts\Dto\ExpiringStockSummary;
use App\Domains\Inventory\Contracts\Dto\LocationItemStockSummary;
use App\Domains\Inventory\Contracts\Dto\LowStockItemSummary;
use App\Domains\Inventory\Contracts\Dto\StockBalanceSummary;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read-side port for materialised Stock Balances (IC §6.2 / §6.3; ADR-170 D-M).
 * Returns DTOs / scalars only.
 */
interface StockBalanceReader
{
    /**
     * Every balance row for one (item, location) — one per batch.
     *
     * @return list<StockBalanceSummary>
     */
    public function forItemLocation(string $itemId, string $locationId): array;

    /**
     * Paginated per-item stock totals for one location, including batch details.
     *
     * @return LengthAwarePaginator<int, LocationItemStockSummary>
     */
    public function forLocation(
        string $locationId,
        int $page,
        int $perPage,
        string $sortBy,
        string $sortDirection,
        string $search,
    ): LengthAwarePaginator;

    /**
     * Every item currently held at one location, with its batches — the source
     * list for the move-stock panel (Phase 8). Not paginated: the panel needs
     * the whole location to choose from, and it is loaded only when a source
     * location is chosen.
     *
     * @return list<LocationItemStockSummary>
     */
    public function stockAtLocation(string $locationId): array;

    /**
     * Paginated stock-balance rows across a facility — one per
     * (item, location, batch) — for the facility-wide stock-on-hand view
     * (§30, §327; ADR-170 D-M). Optionally narrowed to one location and a
     * free-text search over item code/name and location code/name.
     *
     * @return LengthAwarePaginator<int, StockBalanceSummary>
     */
    public function forFacility(
        string $facilityId,
        int $page,
        int $perPage,
        string $sortBy,
        string $sortDirection,
        string $search,
        ?string $locationId = null,
        ?array $departmentIds = null,
    ): LengthAwarePaginator;

    /**
     * Available stock for one item across a facility's locations (ADR-170 D-M).
     * Throws `App\Shared\Domain\NotFound` if the item is unknown in the tenant.
     */
    public function availableForItem(
        string $itemId,
        string $facilityId,
        ?array $departmentIds = null,
    ): AvailableStockSummary;

    /**
     * Batches with stock on hand whose expiry date is inside the window
     * (including already-expired ones), soonest first. Non-expiry batches and
     * zero-balance rows are excluded. The expiry rule itself stays on the
     * `Batch` aggregate (`isExpired`).
     *
     * @return list<ExpiringStockSummary>
     */
    public function expiringForFacility(string $facilityId, int $windowDays): array;

    /**
     * Items at or below their facility minimum level, worst first
     * (frontend-foundation-inventory-operations.md Phase 8, low-stock alerts).
     *
     * On-hand counts USABLE stock across the facility's locations: expired
     * batches are excluded (the same `Batch::isExpired()` rule the dispense
     * gate uses) and reported separately. Items without a level are not
     * tracked and never appear.
     *
     * @return list<LowStockItemSummary>
     */
    public function lowStockForFacility(string $facilityId): array;

    /**
     * The current on-hand total for a (item, location, batch) tuple — for
     * reconciliation (§253 / §408.4). "0.0000" when no balance row exists.
     */
    public function onHandTotal(string $itemId, string $locationId, ?string $batchId): string;
}
