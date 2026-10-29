<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Authorization;

use App\Domains\Procurement\Application\Command\ActivateContract;
use App\Domains\Procurement\Application\Command\AmendContract;
use App\Domains\Procurement\Application\Command\CreateContract;
use App\Domains\Procurement\Application\Command\RenewContract;
use App\Domains\Procurement\Application\Command\TerminateContract;
use App\Domains\Procurement\Domain\Model\Contract;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Contract aggregate policy (ADR-176 D-G).
 *
 * ONE key backs every contract write — `procurement.contracts.manage`. A
 * contract is tenant-scoped, NOT facility-scoped (the `SupplierPolicy` model),
 * so `manage` is a coarse tenant + key check with NO facility refinement.
 * `create` is the class-level check for `CreateContract`; `view` gates every
 * `#[ProtectedQuery(subjectType: Contract::class)]` on `procurement.access`.
 */
final class ContractPolicy extends BasePolicy
{
    public function create(User $user, ?CreateContract $command = null): bool
    {
        return $this->coarse($user, 'procurement.contracts.manage');
    }

    public function manage(
        User $user,
        Contract $contract,
        ActivateContract|TerminateContract|AmendContract|RenewContract|null $command = null,
    ): bool {
        return $this->sameTenant($user, (string) $contract->tenant_id)
            && $this->permits($user, 'procurement.contracts.manage');
    }

    public function view(User $user): bool
    {
        return $this->coarse($user, 'procurement.access');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
