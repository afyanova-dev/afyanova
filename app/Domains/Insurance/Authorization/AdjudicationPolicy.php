<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Adjudication authorization policy (21-insurance-and-payer-domain.md §278, §280; ADR-205).
 * Registered for the `Adjudication` aggregate root.
 *
 * `view` — coarse `insurance.access`.
 * `manage` — coarse `insurance.adjudications.manage`.
 */
final class AdjudicationPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'insurance.access');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'insurance.adjudications.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
