<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Domain\Model\ItemBillingMap;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the item → billing-service map (IC §9; ADR-189 D-A). The
 * FIRST repository parameter of the mapping commands — the bus resolves
 * `BillingCatalogPolicy` (registered for `ItemBillingMap`).
 */
interface ItemBillingMapRepository extends Repository
{
    public function getById(string $id): ItemBillingMap;

    public function getByIdForUpdate(string $id): ItemBillingMap;

    /** The active mapping for an item, `lockForUpdate()` — the supersession guard. */
    public function activeForItemForUpdate(string $invItemId): ?ItemBillingMap;

    /** The active mapping for an item (no lock) — the read path. */
    public function activeForItem(string $invItemId): ?ItemBillingMap;
}
