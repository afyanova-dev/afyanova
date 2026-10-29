<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Authorization;

use App\Domains\Procurement\Application\Command\SetApprovalLimit;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Approval-limit policy (Shape B; 19-procurement-domain.md §45, §228).
 *
 * The limit is ADMINISTRATIVE POLICY, not a deployment setting: a Tenant Admin
 * may set it for any facility in the tenant, a Facility Admin only for the
 * facilities they are actually assigned to. That distinction is not a role
 * name — it is the per-facility permission check below, so a Facility Admin
 * who somehow submits another facility's id is denied by the bus.
 *
 * The command is class-level (`subjectId() === null`) because the scope may
 * not have a row yet and the facility comes from the command, not the subject.
 */
final class ApprovalPolicyPolicy extends BasePolicy
{
    public function manage(User $user, ?SetApprovalLimit $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $command !== null
            && in_array(
                'procurement.approval_limits.manage',
                $this->scope->effectivePermissions($user, $command->facilityId),
                true,
            );
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'procurement.approval_limits.manage');
    }
}
