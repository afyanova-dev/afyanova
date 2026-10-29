<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Infrastructure\Reader;

use App\Domains\Scheduling\Contracts\Dto\SchedulableResourceSummary;
use App\Domains\Scheduling\Domain\Model\SchedulableResource;
use App\Domains\Scheduling\Domain\SchedulableResourceReader;

final class EloquentSchedulableResourceReader implements SchedulableResourceReader
{
    public function findById(string $id): ?SchedulableResourceSummary
    {
        $row = SchedulableResource::query()->whereKey($id)->first();

        return $row !== null ? $this->toSummary($row) : null;
    }

    public function listForFacility(string $facilityId, ?string $resourceType = null): array
    {
        $query = SchedulableResource::query()
            ->where('facility_id', $facilityId)
            ->where('is_active', true);

        if ($resourceType !== null) {
            $query->where('resource_type', $resourceType);
        }

        return array_values($query->get()->map(fn (SchedulableResource $res): SchedulableResourceSummary => $this->toSummary($res))->all());
    }

    private function toSummary(SchedulableResource $res): SchedulableResourceSummary
    {
        return new SchedulableResourceSummary(
            id: (string) $res->id,
            facilityId: (string) $res->facility_id,
            resourceType: $res->resource_type->value,
            resourceRef: (string) $res->resource_ref,
            name: (string) $res->name,
            isActive: (bool) $res->is_active,
        );
    }
}
