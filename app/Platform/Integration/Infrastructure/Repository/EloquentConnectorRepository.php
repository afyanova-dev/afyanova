<?php

declare(strict_types=1);

namespace App\Platform\Integration\Infrastructure\Repository;

use App\Platform\Integration\Domain\Model\IntegrationConnector;
use App\Platform\Integration\Domain\Repository\ConnectorRepository;

final class EloquentConnectorRepository implements ConnectorRepository
{
    public function findById(string $id): ?IntegrationConnector
    {
        return IntegrationConnector::query()->where('id', $id)->first();
    }

    public function findByKey(string $key): ?IntegrationConnector
    {
        return IntegrationConnector::query()->where('key', $key)->first();
    }

    public function listAll(): array
    {
        return array_values(IntegrationConnector::query()->orderBy('name')->get()->all());
    }

    public function save(IntegrationConnector $connector): void
    {
        $connector->save();
    }
}
