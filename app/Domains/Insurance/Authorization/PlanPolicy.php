<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Payer-plan master policy (ADR-198 D-I). Registered for the `Plan` aggregate.
 *
 * `view` — coarse `insurance.access`. `manage` — coarse
 * `insurance.plans.manage`, tenant-wide (plan configuration is organization
 * level — §219 / §220). Distinct from `PayerPolicy` so `CreatePayerPlan`
 * (class-level) resolves to its own key.
 */
final class PlanPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'insurance.access');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'insurance.plans.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
