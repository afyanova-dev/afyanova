<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Repository;

use App\Platform\Integration\Domain\Model\IdMapping;

interface IdMappingRepository
{
    public function findByExternal(string $connectorId, string $externalId): ?IdMapping;

    public function findByInternal(string $connectorId, string $internalDomain, string $internalId): ?IdMapping;

    public function save(IdMapping $mapping): void;
}
