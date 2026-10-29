<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\ItemBillingMapSummary;
use App\Domains\Billing\Domain\ItemBillingMapReader;

final class GetItemBillingMapHandler
{
    public function __construct(private readonly ItemBillingMapReader $reader) {}

    public function __invoke(GetItemBillingMap $query): ?ItemBillingMapSummary
    {
        return $this->reader->activeForItem($query->invItemId);
    }
}
