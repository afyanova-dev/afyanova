<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Contracts;

interface AccountDirectoryApi
{
    /**
     * @param  list<string>  $candidateUserIds
     * @param  list<string>  $excludedUserIds
     * @return list<array{id: string, name: string, email: string, status: string, emailVerified: bool}>
     */
    public function searchFacilityAccounts(
        string $tenantId,
        string $pattern,
        array $candidateUserIds,
        array $excludedUserIds,
    ): array;

    /** @return array{id: string, name: string, email: string, status: string, emailVerified: bool, createdAt: string}|null */
    public function tenantAccount(string $tenantId, string $userId): ?array;

    public function activeVerifiedAccountIdByEmail(string $tenantId, string $email): ?string;

    /** @return list<string> */
    public function facilityInvitationUserIds(string $tenantId, string $facilityId): array;

    public function facilityInvitationExists(string $tenantId, string $facilityId, string $userId): bool;
}
