<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Infrastructure\Repository;

use App\Domains\Scheduling\Domain\Exception\SchedulableResourceNotFoundException;
use App\Domains\Scheduling\Domain\Model\SchedulableResource;
use App\Domains\Scheduling\Domain\SchedulableResourceRepository;
use App\Domains\Scheduling\Domain\ValueObject\ResourceType;
use App\Shared\Domain\EloquentRepository;

final class EloquentSchedulableResourceRepository extends EloquentRepository implements SchedulableResourceRepository
{
    protected function model(): string
    {
        return SchedulableResource::class;
    }

    public function getById(string $id): SchedulableResource
    {
        return SchedulableResource::query()->whereKey($id)->first()
            ?? throw SchedulableResourceNotFoundException::forId($id);
    }

    public function getByIdForUpdate(string $id): SchedulableResource
    {
        return SchedulableResource::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw SchedulableResourceNotFoundException::forId($id);
    }

    public function existsForRef(string $facilityId, ResourceType $type, string $resourceRef): bool
    {
        return SchedulableResource::query()
            ->where('facility_id', $facilityId)
            ->where('resource_type', $type->value)
            ->where('resource_ref', $resourceRef)
            ->exists();
    }
}
