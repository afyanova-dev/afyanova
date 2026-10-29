<?php

declare(strict_types=1);

namespace App\Domains\Billing\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Invoice policy (ADR-190 D-F). Registered for the `Invoice` aggregate.
 *
 * `view` — coarse `billing.access`. `manage` — coarse `billing.invoices.manage`,
 * tenant-wide (the `BillingCatalogPolicy` / `ChargePolicy` posture — invoicing
 * is a billing-clerk function, not a per-facility-refined one).
 */
final class InvoicePolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'billing.access');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'billing.invoices.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
