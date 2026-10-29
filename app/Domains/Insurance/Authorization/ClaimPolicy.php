<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Claim authorization policy (21-insurance-and-payer-domain.md §278, §280; ADR-204).
 * Registered for the `Claim` aggregate root.
 *
 * `view` — coarse `insurance.access`.
 * `create`, `update`, `manage`, `cancel`, `supersede` — coarse `insurance.claims.manage`.
 * `submit` — coarse `insurance.claims.submit` (Sponsor Ruling D3; separation of duties).
 */
final class ClaimPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'insurance.access');
    }

    public function create(User $user): bool
    {
        return $this->coarse($user, 'insurance.claims.manage');
    }

    public function update(User $user): bool
    {
        return $this->coarse($user, 'insurance.claims.manage');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'insurance.claims.manage');
    }

    public function cancel(User $user): bool
    {
        return $this->coarse($user, 'insurance.claims.manage');
    }

    public function supersede(User $user): bool
    {
        return $this->coarse($user, 'insurance.claims.manage');
    }

    public function submit(User $user): bool
    {
        return $this->coarse($user, 'insurance.claims.submit');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
