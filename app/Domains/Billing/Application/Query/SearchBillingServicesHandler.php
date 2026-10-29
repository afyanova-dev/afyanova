<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\BillingServiceSummary;
use App\Domains\Billing\Domain\BillingCatalogReader;

final class SearchBillingServicesHandler
{
    public function __construct(private readonly BillingCatalogReader $reader) {}

    /**
     * @return list<BillingServiceSummary>
     */
    public function __invoke(SearchBillingServices $query): array
    {
        return $this->reader->searchServices($query->category, $query->status);
    }
}
