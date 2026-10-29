<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Contracts\Dto\StockTransferSummary;

/**
 * Read-side port for the Stock Transfer documents of one facility (Phase 8).
 * Returns DTOs only.
 */
interface StockTransferReader
{
    /**
     * Recent transfers for a facility, newest first. Both sides are shown by
     * location code so a transfer reads as "main store → pharmacy counter".
     *
     * @return list<StockTransferSummary>
     */
    public function recentForFacility(string $facilityId, int $limit = 25): array;
}
