<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Contracts\Dto\StockCountLineSummary;
use App\Domains\Inventory\Contracts\Dto\StockCountSummary;

/**
 * Read-side port for stock counts (frontend-foundation-inventory-operations.md
 * Phase 8, stock counts). Returns DTOs only.
 */
interface StockCountReader
{
    /**
     * Recent counts for a facility, newest first.
     *
     * @return list<StockCountSummary>
     */
    public function recentForFacility(string $facilityId, int $limit = 25): array;

    /**
     * One count by id. Throws `App\Shared\Domain\NotFound` when it is not in
     * the tenant.
     */
    public function summary(string $stockCountId): StockCountSummary;

    /**
     * Every line of one count, in item-code order.
     *
     * @return list<StockCountLineSummary>
     */
    public function linesFor(string $stockCountId): array;
}
