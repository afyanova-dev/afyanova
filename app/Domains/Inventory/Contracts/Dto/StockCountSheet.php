<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * One stock count with its sheet: the count header plus every line, for the
 * Stock counts sheet panel (Phase 8). NOT an event payload.
 */
final readonly class StockCountSheet
{
    /**
     * @param  list<StockCountLineSummary>  $lines
     */
    public function __construct(
        public StockCountSummary $count,
        public array $lines,
    ) {}
}
