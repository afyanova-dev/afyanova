<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Repository;

use App\Platform\Integration\Domain\Model\IntegrationCredential;

interface CredentialRepository
{
    public function findById(string $id): ?IntegrationCredential;

    public function findActiveByConnector(string $connectorId): ?IntegrationCredential;

    public function save(IntegrationCredential $credential): void;
}
