<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Pre-authorization policy (ADR-202 D-D). Registered for the `Authorization`
 * aggregate.
 *
 * `view` — coarse `insurance.access`. `request` — coarse
 * `insurance.authorizations.request`. `approve` — coarse
 * `insurance.authorizations.approve` (`Deny` and `Cancel` also gate on
 * `approve`). Tenant-wide: an authorization is not facility-specific (§156).
 */
final class AuthorizationPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'insurance.access');
    }

    public function request(User $user): bool
    {
        return $this->coarse($user, 'insurance.authorizations.request');
    }

    public function approve(User $user): bool
    {
        return $this->coarse($user, 'insurance.authorizations.approve');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
