<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Repository;

use App\Domains\Inventory\Domain\Model\StockLocationType;
use App\Domains\Inventory\Domain\StockLocationTypeRepository;
use App\Shared\Domain\EloquentRepository;

final class EloquentStockLocationTypeRepository extends EloquentRepository implements StockLocationTypeRepository
{
    protected function model(): string
    {
        return StockLocationType::class;
    }

    public function findAvailableByTenantFacilityAndCode(
        string $tenantId,
        string $facilityId,
        string $code,
        bool $includeAllFacilities = false,
    ): ?StockLocationType {
        $query = StockLocationType::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code);
        if (! $includeAllFacilities) {
            $query->where(fn ($scope) => $scope->whereNull('facility_id')->orWhere('facility_id', $facilityId));
        }

        return $query->orderByRaw('facility_id is not null')->first();
    }
}
