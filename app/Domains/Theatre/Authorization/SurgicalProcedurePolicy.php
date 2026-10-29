<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Authorization;

use App\Domains\Theatre\Application\Command\AbortProcedure;
use App\Domains\Theatre\Application\Command\CompleteProcedure;
use App\Domains\Theatre\Application\Command\RecordPlannedProcedure;
use App\Domains\Theatre\Application\Command\StartProcedure;
use App\Domains\Theatre\Domain\Model\SurgicalProcedure;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * SurgicalProcedure aggregate policy (ADR-156 D-C). One key —
 * `theatre.procedures.record` — backs `create` and per-subject `record`.
 */
final class SurgicalProcedurePolicy extends BasePolicy
{
    public function create(User $user, ?RecordPlannedProcedure $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'theatre.procedures.record');
    }

    public function record(User $user, SurgicalProcedure $procedure, StartProcedure|CompleteProcedure|AbortProcedure|null $command = null): bool
    {
        return $this->sameTenant($user, (string) $procedure->tenant_id)
            && in_array(
                'theatre.procedures.record',
                $this->scope->effectivePermissions($user, (string) $procedure->facility_id),
                true,
            );
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'theatre.access');
    }
}
