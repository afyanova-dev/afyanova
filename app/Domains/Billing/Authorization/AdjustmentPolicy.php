<?php

declare(strict_types=1);

namespace App\Domains\Billing\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Adjustment policy (ADR-191 G-3 / D7-D). Registered for the `Adjustment`
 * aggregate — the `WriteOffPolicy` posture.
 *
 * `view` — coarse `billing.access`. `request` — coarse
 * `billing.adjustments.request`. `approve` — coarse
 * `billing.adjustments.approve` (approve / reject / reverse — a DISTINCT key,
 * §164 / §165). All tenant-wide.
 */
final class AdjustmentPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'billing.access');
    }

    public function request(User $user): bool
    {
        return $this->coarse($user, 'billing.adjustments.request');
    }

    public function approve(User $user): bool
    {
        return $this->coarse($user, 'billing.adjustments.approve');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
