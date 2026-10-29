<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Authorization;

use App\Domains\Radiology\Application\Command\AcknowledgeCriticalFinding;
use App\Domains\Radiology\Application\Command\RecordCriticalFinding;
use App\Domains\Radiology\Domain\Model\RadiologyCriticalFinding;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * RadiologyCriticalFinding aggregate policy (ADR-169 D-A/D-F). One key backs
 * every write: `radiology.critical.manage` (§120's reconciled name).
 * `create` is the class-level check for `RecordCriticalFinding` (facility comes
 * from the report — the handler's ADR-133 refinement enforces the real
 * per-report-facility scope). `acknowledge` is the instance-level check.
 * `view` gates the `#[ProtectedQuery]` reads.
 */
final class RadiologyCriticalFindingPolicy extends BasePolicy
{
    public function create(User $user, ?RecordCriticalFinding $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'radiology.critical.manage');
    }

    public function acknowledge(User $user, RadiologyCriticalFinding $finding, ?AcknowledgeCriticalFinding $command = null): bool
    {
        return $this->sameTenant($user, (string) $finding->tenant_id)
            && in_array('radiology.critical.manage', $this->scope->effectivePermissions($user, (string) $finding->facility_id), true);
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'radiology.access');
    }
}
