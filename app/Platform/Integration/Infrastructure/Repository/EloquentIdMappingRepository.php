<?php

declare(strict_types=1);

namespace App\Platform\Integration\Infrastructure\Repository;

use App\Platform\Integration\Domain\Model\IdMapping;
use App\Platform\Integration\Domain\Repository\IdMappingRepository;

final class EloquentIdMappingRepository implements IdMappingRepository
{
    public function findByExternal(string $connectorId, string $externalId): ?IdMapping
    {
        return IdMapping::query()
            ->where('connector_id', $connectorId)
            ->where('external_id', $externalId)
            ->first();
    }

    public function findByInternal(string $connectorId, string $internalDomain, string $internalId): ?IdMapping
    {
        return IdMapping::query()
            ->where('connector_id', $connectorId)
            ->where('internal_domain', $internalDomain)
            ->where('internal_id', $internalId)
            ->first();
    }

    public function save(IdMapping $mapping): void
    {
        $mapping->save();
    }
}
