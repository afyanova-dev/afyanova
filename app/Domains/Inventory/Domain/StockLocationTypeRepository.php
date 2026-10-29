<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Domain\Model\StockLocationType;
use App\Shared\Domain\Repository;

interface StockLocationTypeRepository extends Repository
{
    public function findAvailableByTenantFacilityAndCode(
        string $tenantId,
        string $facilityId,
        string $code,
        bool $includeAllFacilities = false,
    ): ?StockLocationType;
}
