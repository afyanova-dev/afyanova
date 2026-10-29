<?php

declare(strict_types=1);

namespace App\Domains\HR\Authorization;

use App\Domains\HR\Application\Command\LinkStaffToUser;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;
use Illuminate\Support\Facades\DB;

/**
 * Authorization policy for StaffUserLink (ADR-206).
 */
final class StaffUserLinkPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'hr.staff.read') || $this->coarse($user, 'hr.staff_user_link.manage');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'hr.staff_user_link.manage');
    }

    public function link(User $user, ?LinkStaffToUser $command = null): bool
    {
        if ($this->coarse($user, 'hr.staff_user_link.manage')) {
            return true;
        }

        $facilityId = $this->context->facilityId();
        if ($command === null
            || ! is_string($facilityId)
            || ! $this->context->hasTenant()
            || (string) $user->tenant_id !== $this->context->tenantId()
            || ! $this->scope->withinFacilityScope($user, $facilityId)) {
            return false;
        }

        $permissions = $this->scope->effectivePermissions($user, $facilityId);

        return in_array('admin.workspace.access', $permissions, true)
            && in_array('identity.assignments.manage', $permissions, true)
            && DB::table('hr_assignments')
                ->where('tenant_id', $this->context->tenantId())
                ->where('facility_id', $facilityId)
                ->where('staff_id', $command->staffId)
                ->where('status', 'ACTIVE')
                ->exists();
    }

    public function unlink(User $user): bool
    {
        return $this->coarse($user, 'hr.staff_user_link.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
