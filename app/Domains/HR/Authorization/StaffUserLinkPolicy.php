<?php

declare(strict_types=1);

namespace App\Domains\HR\Authorization;

use App\Domains\HR\Application\Command\LinkStaffToUser;
use App\Domains\HR\Application\Command\UnlinkStaffFromUser;
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
        if ($command === null) {
            return false;
        }

        $permissions = $this->facilityPermissionsForStaffInContext($user, $command->staffId);
        if ($permissions === null) {
            return false;
        }

        return in_array('hr.staff_user_link.manage', $permissions, true)
            || (in_array('admin.workspace.access', $permissions, true)
                && in_array('identity.assignments.manage', $permissions, true));
    }

    public function unlink(User $user, ?UnlinkStaffFromUser $command = null): bool
    {
        if ($command === null) {
            return false;
        }

        $permissions = $this->facilityPermissionsForStaffInContext($user, $command->staffId);

        return $permissions !== null && in_array('hr.staff_user_link.manage', $permissions, true);
    }

    /**
     * @return list<string>|null
     */
    private function facilityPermissionsForStaffInContext(User $user, string $staffId): ?array
    {
        $facilityId = $this->context->facilityId();
        if (! is_string($facilityId)
            || $facilityId === ''
            || ! $this->context->hasTenant()
            || (string) $user->tenant_id !== $this->context->tenantId()
            || ! $this->scope->withinFacilityScope($user, $facilityId)) {
            return null;
        }

        $tenantId = $this->context->tenantId();
        $hasActiveAssignment = DB::table('hr_assignments')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('staff_id', $staffId)
            ->where('status', 'ACTIVE')
            ->exists();
        $hasPendingPlacement = DB::table('hr_assignment_requests')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('staff_id', $staffId)
            ->where('status', 'PENDING')
            ->exists();
        if (! $hasActiveAssignment && ! $hasPendingPlacement) {
            return null;
        }

        return $this->scope->effectivePermissions($user, $facilityId);
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
