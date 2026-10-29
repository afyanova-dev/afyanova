<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\TariffResolution;
use App\Domains\Billing\Domain\BillingCatalogReader;

final class GetPriceListHandler
{
    public function __construct(private readonly BillingCatalogReader $reader) {}

    /**
     * @return list<TariffResolution>
     */
    public function __invoke(GetPriceList $query): array
    {
        return $this->reader->priceList($query->facilityId, $query->onDate);
    }
}
