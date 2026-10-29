<?php

declare(strict_types=1);

namespace App\Domains\HR\Authorization;

use App\Domains\HR\Domain\Model\Staff;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Authorization policy for Staff aggregate root (ADR-206).
 */
final class StaffPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'hr.staff.read') || $this->coarse($user, 'hr.staff.manage');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'hr.staff.manage');
    }

    public function create(User $user): bool
    {
        return $this->coarse($user, 'hr.staff.manage');
    }

    public function onboard(User $user, Staff $staff): bool
    {
        return $this->coarse($user, 'hr.staff.manage');
    }

    public function suspend(User $user, Staff $staff): bool
    {
        return $this->coarse($user, 'hr.staff.manage');
    }

    public function reinstate(User $user, Staff $staff): bool
    {
        return $this->coarse($user, 'hr.staff.manage');
    }

    public function terminate(User $user, Staff $staff): bool
    {
        return $this->coarse($user, 'hr.staff.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
