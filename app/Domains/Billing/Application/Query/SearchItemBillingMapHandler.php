<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\ItemBillingMapSummary;
use App\Domains\Billing\Domain\ItemBillingMapReader;

final class SearchItemBillingMapHandler
{
    public function __construct(private readonly ItemBillingMapReader $reader) {}

    /**
     * @return list<ItemBillingMapSummary>
     */
    public function __invoke(SearchItemBillingMap $query): array
    {
        return $this->reader->search($query->billingServiceId);
    }
}
