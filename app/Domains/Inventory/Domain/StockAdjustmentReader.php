<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Contracts\Dto\StockAdjustmentSummary;

/**
 * Read-side port for the Stock Adjustment workflow (IC §6.2 / §6.3;
 * ADR-170 D-M). Returns DTOs only.
 */
interface StockAdjustmentReader
{
    /** Throws `App\Shared\Domain\NotFound` if absent from the tenant scope (RLS). */
    public function summary(string $adjustmentId): StockAdjustmentSummary;

    /**
     * Adjustments at a facility — the approver worklist. Optionally filtered by
     * status.
     *
     * @return list<StockAdjustmentSummary>
     */
    public function listForFacility(string $facilityId, ?string $status = null): array;
}
