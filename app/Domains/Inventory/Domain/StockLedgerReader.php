<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Contracts\Dto\StockLedgerSummary;

/**
 * Read-side port for the immutable Stock Movement ledger (IC §6.2 / §6.3;
 * ADR-170 D-J / D-M). Returns DTOs / scalars only.
 */
interface StockLedgerReader
{
    /**
     * The movement history for one (item, location) with running balances and
     * a reconciliation drift (§84 / §214 / §253).
     */
    public function history(string $itemId, string $locationId): StockLedgerSummary;

    /**
     * Σ (CASE direction WHEN 'IN' THEN quantity ELSE −quantity END) for a
     * (item, location, batch) tuple — "0.0000" when there are no movements
     * (§84 / §253 / §408.4).
     */
    public function signedMovementSum(string $itemId, string $locationId, ?string $batchId): string;

    /**
     * Whether the item has any stock movement at all (any location/batch) —
     * the "has stock history" test behind the item-reclassification guard
     * (`inventory-item-master-spec.md` §5).
     */
    public function hasMovementsForItem(string $itemId): bool;
}
