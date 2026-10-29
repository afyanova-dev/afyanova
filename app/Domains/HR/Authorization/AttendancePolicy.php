<?php

declare(strict_types=1);

namespace App\Domains\HR\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Authorization policy for AttendanceEvent and AttendanceSession (25-hr-and-workforce-domain.md §10; ADR-206).
 */
final class AttendancePolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'hr.staff.read') || $this->coarse($user, 'hr.attendance.manage');
    }

    public function ingest(User $user): bool
    {
        return $this->coarse($user, 'hr.attendance.ingest') || $this->coarse($user, 'hr.attendance.manage');
    }

    public function create(User $user): bool
    {
        return $this->coarse($user, 'hr.attendance.ingest') || $this->coarse($user, 'hr.attendance.manage');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'hr.attendance.manage');
    }

    public function close(User $user): bool
    {
        return $this->coarse($user, 'hr.attendance.manage');
    }

    public function reconcile(User $user): bool
    {
        return $this->coarse($user, 'hr.attendance.reconcile') || $this->coarse($user, 'hr.attendance.manage');
    }

    public function rebuild(User $user): bool
    {
        return $this->coarse($user, 'hr.attendance.manage');
    }

    public function raiseException(User $user): bool
    {
        return $this->coarse($user, 'hr.attendance.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
