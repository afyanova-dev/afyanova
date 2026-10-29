<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Reader;

use App\Domains\Inventory\Contracts\Dto\StockLocationSummary;
use App\Domains\Inventory\Domain\Model\StockLocation;
use App\Domains\Inventory\Domain\StockLocationReader;
use App\Shared\Domain\NotFound;

final class EloquentStockLocationReader implements StockLocationReader
{
    public function summary(string $locationId): StockLocationSummary
    {
        $location = StockLocation::query()->whereKey($locationId)->first()
            ?? throw NotFound::aggregate(StockLocation::class, $locationId);

        return $this->toSummary($location);
    }

    public function listForFacility(
        string $facilityId,
        ?bool $active = null,
        ?array $departmentIds = null,
    ): array {
        $query = StockLocation::query()->where('facility_id', $facilityId);

        if ($departmentIds !== null) {
            $query->whereIn('department_id', $departmentIds);
        }

        if ($active !== null) {
            $query->where('active', $active);
        }

        return array_values(
            $query->orderBy('location_type')->orderBy('name')->orderBy('id')->get()
                ->map(fn (StockLocation $location): StockLocationSummary => $this->toSummary($location))
                ->all()
        );
    }

    private function toSummary(StockLocation $location): StockLocationSummary
    {
        return new StockLocationSummary(
            locationId: (string) $location->id,
            facilityId: (string) $location->facility_id,
            departmentId: $location->department_id !== null ? (string) $location->department_id : null,
            code: (string) $location->code,
            name: (string) $location->name,
            locationType: (string) $location->location_type,
            parentLocationId: $location->parent_location_id !== null ? (string) $location->parent_location_id : null,
            active: (bool) $location->active,
            version: (int) $location->version,
        );
    }
}
