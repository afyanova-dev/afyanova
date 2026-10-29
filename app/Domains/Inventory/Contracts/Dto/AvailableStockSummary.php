<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * Available stock for one item across a facility's locations
 * (18-inventory-and-warehousing-domain.md §29, §30, §327; ADR-170 D-M —
 * facility-scoped, per-item, per-location breakdown; the consumer-facing read
 * Pharmacy / Theatre will use in their phases). NOT an event payload.
 *
 * @param  list<StockBalanceSummary>  $byLocation
 */
final readonly class AvailableStockSummary
{
    /**
     * @param  list<StockBalanceSummary>  $byLocation
     */
    public function __construct(
        public string $itemId,
        public string $itemCode,
        public string $facilityId,
        public string $uom,
        public string $totalOnHandQty,
        public string $totalReservedQty,
        public string $totalAvailableQty,
        public array $byLocation,
    ) {}
}
