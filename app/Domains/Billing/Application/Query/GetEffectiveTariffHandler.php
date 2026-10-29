<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\TariffResolution;
use App\Domains\Billing\Domain\BillingCatalogReader;

final class GetEffectiveTariffHandler
{
    public function __construct(private readonly BillingCatalogReader $reader) {}

    public function __invoke(GetEffectiveTariff $query): TariffResolution
    {
        return $this->reader->resolveTariff($query->billingServiceId, $query->facilityId, $query->onDate);
    }
}
