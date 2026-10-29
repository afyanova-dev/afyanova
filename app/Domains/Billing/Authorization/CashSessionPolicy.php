<?php

declare(strict_types=1);

namespace App\Domains\Billing\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Cash Session policy (ADR-191 C6). Registered for the `CashSession` aggregate.
 *
 * `view` — coarse `billing.access`. `manage` — coarse
 * `billing.cash_sessions.manage` (`OpenCashSession` / `CloseCashSession` — the
 * cashier). `reconcile` — coarse `billing.cash_sessions.reconcile`
 * (`ReconcileCashSession` — a DISTINCT key from `.manage`, §165 / §157: the
 * supervisor reviews a variance). All tenant-wide (the `PaymentPolicy`
 * posture); the self-reconcile bar for a non-zero variance is a handler
 * business rule.
 */
final class CashSessionPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'billing.access');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'billing.cash_sessions.manage');
    }

    public function reconcile(User $user): bool
    {
        return $this->coarse($user, 'billing.cash_sessions.reconcile');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
