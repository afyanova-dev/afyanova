<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Payer tariff policy (ADR-203). Registered for the `PayerTariff` entity / aggregate.
 *
 * `view` — coarse `insurance.access` (tariff queries: `GetContractTariffs`, `FindEffectivePayerTariff`).
 * `manage` — coarse `insurance.tariffs.manage`, tenant-wide: contracted tariffs are
 * organization-level, commercially sensitive pricing schedules (§154 / §281; ADR-203).
 */
final class PayerTariffPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'insurance.access');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'insurance.tariffs.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
