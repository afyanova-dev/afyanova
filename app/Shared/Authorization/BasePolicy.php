<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Models\User;
use App\Shared\Tenancy\TenantContext;

/**
 * Base class for every aggregate policy (IMPLEMENTATION-CONTRACTS.md §12,
 * docs/00-foundation/03-identity-rbac.md §28 — the authorization formula).
 *
 * A concrete policy composes these helpers:
 *   permission  ∧  tenant match  ∧  facility scope  ∧  (domain / SoD rule)
 *
 * The final authoritative check is the command-bus authorize middleware
 * (PHASE 4); a Form Request's `authorize()` uses `$user->can($key)` for the
 * coarse early-403 only. Both hit the same key.
 */
abstract class BasePolicy
{
    public function __construct(
        protected readonly TenantContext $context,
        protected readonly ScopeChecker $scope,
    ) {}

    /**
     * Does the user hold this permission key in the current facility context?
     * (Gate resolves permission keys through {@see User::hasPermissionKey}.)
     */
    protected function permits(User $user, string $key): bool
    {
        return $user->hasPermissionKey($key);
    }

    /**
     * Is the target row in the actor's tenant? (RLS already guarantees the row
     * is visible; this is the explicit app-layer statement of the same.)
     */
    protected function sameTenant(User $user, string $targetTenantId): bool
    {
        return $this->context->hasTenant()
            && $this->context->tenantId() === $targetTenantId
            && (string) $user->tenant_id === $targetTenantId;
    }

    /**
     * Is the target's facility within the actor's assigned scope?
     */
    protected function inFacilityScope(User $user, string $targetFacilityId): bool
    {
        return $this->scope->withinFacilityScope($user, $targetFacilityId);
    }

    /**
     * Is the target's facility the request's active facility? Commands execute
     * inside one facility context — a forged id from another facility must
     * fail even when the actor could work there.
     */
    protected function inActiveFacility(string $targetFacilityId): bool
    {
        $activeFacilityId = $this->context->facilityId();

        return $activeFacilityId !== null && $activeFacilityId !== '' && $activeFacilityId === $targetFacilityId;
    }
}
