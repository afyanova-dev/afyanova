<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Contracts;

use App\Models\User;
use App\Shared\Authorization\ScopeChecker;

interface FacilityUserInvitationApi
{
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
