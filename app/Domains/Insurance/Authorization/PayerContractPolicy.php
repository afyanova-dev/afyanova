<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Payer contract policy (ADR-203). Registered for the `PayerContract` aggregate.
 *
 * `view` — coarse `insurance.access` (ADR-203).
 * `create`, `activate`, `terminate`, `manage` — coarse `insurance.contracts.manage`,
 * tenant-wide: provider-payer contracts are organization-level governance (§219 / §220).
 */
final class PayerContractPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'insurance.access');
    }

    public function create(User $user): bool
    {
        return $this->coarse($user, 'insurance.contracts.manage');
    }

    public function activate(User $user): bool
    {
        return $this->coarse($user, 'insurance.contracts.manage');
    }

    public function terminate(User $user): bool
    {
        return $this->coarse($user, 'insurance.contracts.manage');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'insurance.contracts.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
