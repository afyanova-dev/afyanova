<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Reader;

use App\Domains\Inventory\Contracts\Dto\StockLocationTypeSummary;
use App\Domains\Inventory\Domain\Model\StockLocationType;
use App\Domains\Inventory\Domain\StockLocationTypeReader;

final class EloquentStockLocationTypeReader implements StockLocationTypeReader
{
    public function listForTenantAndFacility(string $tenantId, ?string $facilityId): array
    {
        $query = StockLocationType::query()
            ->where('tenant_id', $tenantId)
            ->where(fn ($query) => $query->whereNull('facility_id')
                ->when($facilityId !== null, fn ($types) => $types->orWhere('facility_id', $facilityId)));

        return $query
            ->orderBy('facility_id')
            ->orderBy('name')
            ->get()
            ->map(fn (StockLocationType $type): StockLocationTypeSummary => new StockLocationTypeSummary(
                code: (string) $type->code,
                name: (string) $type->name,
                scope: $type->facility_id === null ? 'TENANT' : 'FACILITY',
            ))
            ->all();
    }
}
