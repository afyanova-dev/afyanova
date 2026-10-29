<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Contracts\Dto\ItemSummary;

/**
 * Read-side port for the Item Master catalog (IC §6.2 / §6.3; ADR-170 D-M).
 * Returns DTOs only — never an Eloquent model (IC §9).
 *
 * `summary` backs `GetItem` (by-id) and is also the intra-domain read the
 * movement handlers use to snapshot the `uom` and inspect the tracking rules.
 * `list` backs `GetItems` (the catalog picker).
 */
interface ItemReader
{
    /** Throws `App\Shared\Domain\NotFound` if absent from the tenant scope (RLS). */
    public function summary(string $itemId): ItemSummary;

    /**
     * The tenant's item catalog, optionally filtered.
     *
     * @return list<ItemSummary>
     */
    public function list(?string $itemType = null, ?bool $active = null): array;
}
