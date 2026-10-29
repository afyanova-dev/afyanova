<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\BillingServiceSummary;
use App\Domains\Billing\Domain\BillingCatalogReader;

final class GetBillingServiceHandler
{
    public function __construct(private readonly BillingCatalogReader $reader) {}

    public function __invoke(GetBillingService $query): BillingServiceSummary
    {
        return $this->reader->serviceSummary($query->billingServiceId);
    }
}
