<?php

declare(strict_types=1);

namespace App\Domains\Billing\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Charge + billable-event policy (ADR-189 D-I). Registered for BOTH the `Charge`
 * and `BillableEvent` aggregates.
 *
 * `view` — coarse `billing.access` (the reads take one id in, one account's / one
 * event's rows out). `manage` — coarse `billing.charges.manage`, tenant-wide:
 * `RetryBillableEvent` is a revenue-leakage ops action (§130 / §131), the same
 * uniform-tenant-wide posture as `BillingCatalogPolicy` (ADR-188). The
 * `CaptureBillableEvent` / `ReverseBillableEvent` system commands are not
 * `AuthorizesAgainst` — no policy call (IC §7.3).
 */
final class ChargePolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'billing.access');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'billing.charges.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
