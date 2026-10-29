<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Repository;

use App\Platform\Integration\Domain\Model\IntegrationConnector;

interface ConnectorRepository
{
    public function findById(string $id): ?IntegrationConnector;

    public function findByKey(string $key): ?IntegrationConnector;

    /** @return list<IntegrationConnector> */
    public function listAll(): array;

    public function save(IntegrationConnector $connector): void;
}
