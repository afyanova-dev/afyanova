<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Repository;

use App\Domains\Inventory\Domain\Model\StockLocation;
use App\Domains\Inventory\Domain\StockLocationRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see StockLocationRepository} (IC §9; ADR-170 D-D).
 */
final class EloquentStockLocationRepository extends EloquentRepository implements StockLocationRepository
{
    protected function model(): string
    {
        return StockLocation::class;
    }

    public function getById(string $id): StockLocation
    {
        return StockLocation::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(StockLocation::class, $id);
    }

    public function getByIdForUpdate(string $id): StockLocation
    {
        return StockLocation::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(StockLocation::class, $id);
    }

    public function findActiveByCode(string $tenantId, string $facilityId, string $code): ?StockLocation
    {
        return StockLocation::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('code', $code)
            ->where('active', true)
            ->first();
    }
}
