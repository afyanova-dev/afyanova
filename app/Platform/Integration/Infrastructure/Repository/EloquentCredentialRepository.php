<?php

declare(strict_types=1);

namespace App\Platform\Integration\Infrastructure\Repository;

use App\Platform\Integration\Domain\Enum\CredentialStatus;
use App\Platform\Integration\Domain\Model\IntegrationCredential;
use App\Platform\Integration\Domain\Repository\CredentialRepository;

final class EloquentCredentialRepository implements CredentialRepository
{
    public function findById(string $id): ?IntegrationCredential
    {
        return IntegrationCredential::query()->where('id', $id)->first();
    }

    public function findActiveByConnector(string $connectorId): ?IntegrationCredential
    {
        return IntegrationCredential::query()
            ->where('connector_id', $connectorId)
            ->whereIn('status', [CredentialStatus::ACTIVE->value, CredentialStatus::ROTATING->value])
            ->latest('created_at')
            ->first();
    }

    public function save(IntegrationCredential $credential): void
    {
        $credential->save();
    }
}
