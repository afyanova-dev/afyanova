<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Contracts\Dto\StockLocationTypeSummary;

interface StockLocationTypeReader
{
    /** @return list<StockLocationTypeSummary> */
    public function listForTenantAndFacility(string $tenantId, ?string $facilityId): array;
}
