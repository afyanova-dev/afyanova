<?php

declare(strict_types=1);

namespace App\Domains\Billing\Authorization;

use App\Domains\Billing\Application\Command\CloseFinancialAccount;
use App\Domains\Billing\Application\Command\OpenFinancialAccount;
use App\Domains\Billing\Domain\Model\FinancialAccount;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Financial Account policy (ADR-187 D-G).
 *
 * `view` is a coarse `billing.access` check (the reads carry no cross-account
 * data — one account id in, one account's rows out). `open` is class-level:
 * `billing.accounts.manage` at the command's `facilityId` (there is no account
 * row yet). `close` is per-subject: `billing.accounts.manage` at the account's
 * facility — the `PharmacyOrderPolicy::manage` shape.
 */
final class FinancialAccountPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'billing.access');
    }

    public function open(User $user, ?OpenFinancialAccount $command = null): bool
    {
        return $command !== null
            && $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && in_array(
                'billing.accounts.manage',
                $this->scope->effectivePermissions($user, $command->facilityId),
                true,
            );
    }

    public function close(User $user, FinancialAccount $account, ?CloseFinancialAccount $command = null): bool
    {
        return $this->sameTenant($user, (string) $account->tenant_id)
            && in_array(
                'billing.accounts.manage',
                $this->scope->effectivePermissions($user, (string) $account->facility_id),
                true,
            );
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
