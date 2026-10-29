<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Authorization;

use App\Domains\Laboratory\Application\Command\RecordQcRun;
use App\Domains\Laboratory\Application\Command\ReviewQcRun;
use App\Domains\Laboratory\Domain\Model\LabQcRun;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * LabQcRun aggregate policy (ADR-163 D-A/D-J). `create` and `review` both gate
 * on `laboratory.quality.manage` (§128) — one "manage QC" responsibility.
 */
final class LabQcRunPolicy extends BasePolicy
{
    public function create(User $user, ?RecordQcRun $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'laboratory.quality.manage');
    }

    public function review(User $user, LabQcRun $run, ?ReviewQcRun $command = null): bool
    {
        return $this->sameTenant($user, (string) $run->tenant_id)
            && in_array('laboratory.quality.manage', $this->scope->effectivePermissions($user, (string) $run->facility_id), true);
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'laboratory.access');
    }
}
