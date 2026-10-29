<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * Aggregated stock for an item at one location with its batch-level balances.
 */
final readonly class LocationItemStockSummary
{
    /**
     * @param  list<StockBalanceSummary>  $batches
     */
    public function __construct(
        public string $itemId,
        public string $itemCode,
        public string $itemName,
        public string $onHandQty,
        public string $reservedQty,
        public string $availableQty,
        public string $uom,
        public array $batches,
    ) {}
}
