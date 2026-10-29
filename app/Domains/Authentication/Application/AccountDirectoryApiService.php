<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Application;

use App\Domains\Authentication\Contracts\AccountDirectoryApi;
use App\Domains\Authentication\Infrastructure\Reader\AccountDirectoryReader;

final class AccountDirectoryApiService implements AccountDirectoryApi
{
    public function __construct(private readonly AccountDirectoryReader $reader) {}

    public function searchFacilityAccounts(
        string $tenantId,
        string $pattern,
        array $candidateUserIds,
        array $excludedUserIds,
    ): array {
        return $this->reader->searchFacilityAccounts($tenantId, $pattern, $candidateUserIds, $excludedUserIds);
    }

    public function tenantAccount(string $tenantId, string $userId): ?array
    {
        return $this->reader->tenantAccount($tenantId, $userId);
    }

    public function activeVerifiedAccountIdByEmail(string $tenantId, string $email): ?string
    {
        return $this->reader->activeVerifiedAccountIdByEmail($tenantId, $email);
    }

    public function facilityInvitationUserIds(string $tenantId, string $facilityId): array
    {
        return $this->reader->facilityInvitationUserIds($tenantId, $facilityId);
    }

    public function facilityInvitationExists(string $tenantId, string $facilityId, string $userId): bool
    {
        return $this->reader->facilityInvitationExists($tenantId, $facilityId, $userId);
    }
}
