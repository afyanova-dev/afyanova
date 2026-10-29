<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * One materialised stock-balance row — per (item, location, batch)
 * (18-inventory-and-warehousing-domain.md §29, §30, §327; ADR-170 D-M).
 * `availableQty = onHandQty − reservedQty` (§30 — computed, never stored).
 *
 * `eligible` is the Phase 4 availability flag: true when the row's batch is not
 * expired (always true for a non-batch row). It is the per-location / per-batch
 * eligibility the dispense selection relies on; `AvailableStockSummary` totals
 * sum eligible rows only. A future batch status (quarantine / recall, Inv.6)
 * extends this flag rather than adding a parallel one. NOT an event payload.
 */
final readonly class StockBalanceSummary
{
    public function __construct(
        public string $balanceId,
        public string $itemId,
        public string $itemCode,
        public string $itemName,
        public string $facilityId,
        public string $locationId,
        public string $locationCode,
        public ?string $batchId,
        public ?string $batchNumber,
        public ?string $expiryDate,
        public string $onHandQty,
        public string $reservedQty,
        public string $availableQty,
        public string $uom,
        public bool $eligible,
    ) {}
}
