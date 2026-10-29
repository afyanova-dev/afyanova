<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Payer master policy (ADR-198 D-I). Registered for the `Payer` aggregate.
 *
 * `view` — coarse `insurance.access` (the payer master carries no per-facility
 * data; one payer / scope in, its rows out). `manage` — coarse
 * `insurance.payers.manage`, tenant-wide: a payer is organization-level
 * configuration (§219 / §220). The facility / network-contract refinement
 * (§47 / §204) is Ins.6, not here.
 *
 * A separate `PlanPolicy` governs `Plan` — a single policy cannot distinguish
 * two class-level (`subjectId() === null`) creates.
 */
final class PayerPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'insurance.access');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'insurance.payers.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
