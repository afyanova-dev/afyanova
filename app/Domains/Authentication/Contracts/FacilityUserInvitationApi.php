<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Contracts;

use App\Domains\Authentication\Contracts\Dto\FacilityInvitationSummary;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;

interface FacilityUserInvitationApi
{
    /**
     * @return list<array<string, mixed>>
     */
    public function facilityInvitationsForWorklist(string $tenantId, string $facilityId): array;

    public function pendingFacilityInvitationForAccount(
        string $tenantId,
        string $facilityId,
        string $userId,
    ): ?FacilityInvitationSummary;

    /**
     * @return list<array<string, mixed>>
     */
    public function invitationHistoryForStaff(
        string $tenantId,
        string $facilityId,
        string $staffId,
        string $userId,
    ): array;

    public function revokeFacilityInvitation(
        string $tenantId,
        string $facilityId,
        string $invitationId,
        User $actor,
        string $reason,
        ?string $staffId = null,
    ): void;

    /**
     * @param  string|list<string>  $roleKey
     * @return array{email: string, userId: string, invitationId: string}
     */
    public function inviteFacilityUser(
        string $tenantId,
        string|array $roleKey,
        string $facilityId,
        string $name,
        string $email,
        string $changeReference,
        string $reason,
        User $actor,
        ScopeChecker $scopeChecker,
        ?string $staffId = null,
    ): array;
}
