<?php

declare(strict_types=1);

namespace App\Domains\Billing\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Billing catalog + tariff policy (ADR-188 D-F). Registered for BOTH
 * `BillingService` and `Tariff` aggregates.
 *
 * `view` — coarse `billing.access` (the catalog + tariffs carry no per-facility
 * data; one service / scope in, its rows out). `manage` — coarse
 * `billing.catalog.manage`, tenant-wide: the base tariff (facility overrides
 * included) is organization pricing configuration (§22 / §231). Per-facility
 * override *authorization* (§23 "only if policy allows") is a future policy
 * flag, not Bill.2.
 */
final class BillingCatalogPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'billing.access');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'billing.catalog.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
