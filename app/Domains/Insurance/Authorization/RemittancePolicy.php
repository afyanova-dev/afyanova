<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Remittance authorization policy (21-insurance-and-payer-domain.md §278, §280; ADR-205).
 * Registered for the `Remittance` aggregate root.
 *
 * `view` — coarse `insurance.access`.
 * `record`, `manage` — coarse `insurance.remittance.record`.
 */
final class RemittancePolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'insurance.access');
    }

    public function record(User $user): bool
    {
        return $this->coarse($user, 'insurance.remittance.record');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'insurance.remittance.record');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
