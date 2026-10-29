<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * The stock-movement history for one (item, location) with the current on-hand
 * balance and a reconciliation drift (should be "0.0000")
 * (18-inventory-and-warehousing-domain.md §84, §86, §214, §253, §408.4;
 * ADR-170 D-J / D-M). NOT an event payload.
 *
 * @param  list<StockLedgerEntrySummary>  $entries
 */
final readonly class StockLedgerSummary
{
    /**
     * @param  list<StockLedgerEntrySummary>  $entries
     */
    public function __construct(
        public string $itemId,
        public string $locationId,
        public string $currentOnHandQty,
        public string $ledgerSum,
        public string $drift,
        public array $entries,
    ) {}
}
