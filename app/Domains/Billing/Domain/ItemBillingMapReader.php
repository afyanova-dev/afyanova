<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Contracts\Dto\ItemBillingMapSummary;

/**
 * Read-side port for the item → billing-service map (IC §6.2 / §6.3;
 * ADR-189 D-A). Returns DTOs only.
 */
interface ItemBillingMapReader
{
    /** The current active mapping for an item, or null. */
    public function activeForItem(string $invItemId): ?ItemBillingMapSummary;

    /**
     * All mappings (active + inactive), newest first; optional service filter.
     *
     * @return list<ItemBillingMapSummary>
     */
    public function search(?string $billingServiceId): array;
}
