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
 * `billing.accounts.manage` at the command's `facilityId` in the current
 * context (there is no account row yet). `close` is per-subject:
 * `billing.accounts.manage` at the account's facility in the current context —
 * the `PharmacyOrderPolicy::manage` shape.
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
            && $this->scope->hasFacilityPermissionInContext(
                $user,
                $this->context,
                $command->facilityId,
                'billing.accounts.manage',
            );
    }

    public function close(User $user, FinancialAccount $account, ?CloseFinancialAccount $command = null): bool
    {
        return $this->sameTenant($user, (string) $account->tenant_id)
            && $this->scope->hasFacilityPermissionInContext(
                $user,
                $this->context,
                (string) $account->facility_id,
                'billing.accounts.manage',
            );
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
