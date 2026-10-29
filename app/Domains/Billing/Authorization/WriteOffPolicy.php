<?php

declare(strict_types=1);

namespace App\Domains\Billing\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Write-off policy (ADR-191 G-3 / D7). Registered for the `WriteOff` aggregate.
 *
 * `view` — coarse `billing.access`. `request` — coarse
 * `billing.writeoffs.request`. `approve` — coarse `billing.writeoffs.approve`
 * (approve / reject / reverse — a DISTINCT key, §164 / §165). All tenant-wide
 * (the `PaymentPolicy` posture). The requester ≠ approver bar for a
 * dual-control write-off is a handler business rule (`SelfApprovalNotPermitted`).
 */
final class WriteOffPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'billing.access');
    }

    public function request(User $user): bool
    {
        return $this->coarse($user, 'billing.writeoffs.request');
    }

    public function approve(User $user): bool
    {
        return $this->coarse($user, 'billing.writeoffs.approve');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
