<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Authorization;

use App\Domains\Laboratory\Application\Command\AcknowledgeCriticalResult;
use App\Domains\Laboratory\Application\Command\RecordCriticalResult;
use App\Domains\Laboratory\Domain\Model\LabCriticalResult;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * LabCriticalResult aggregate policy (ADR-161 D-A/D-J).
 *
 * `create` (identification) and `acknowledge` both gate on
 * `laboratory.critical.manage` — one "handle critical results" responsibility
 * in Lab.3c (a Clinical-side acknowledgement key is a future refinement, §103).
 */
final class LabCriticalResultPolicy extends BasePolicy
{
    public function create(User $user, ?RecordCriticalResult $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'laboratory.critical.manage');
    }

    public function acknowledge(User $user, LabCriticalResult $critical, ?AcknowledgeCriticalResult $command = null): bool
    {
        return $this->sameTenant($user, (string) $critical->tenant_id)
            && in_array('laboratory.critical.manage', $this->scope->effectivePermissions($user, (string) $critical->facility_id), true);
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'laboratory.access');
    }
}
