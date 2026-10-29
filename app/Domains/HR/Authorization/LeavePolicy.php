<?php

declare(strict_types=1);

namespace App\Domains\HR\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Authorization policy for LeaveType, LeaveLedgerEntry, and LeaveRequest (25-hr-and-workforce-domain.md §6, §10; ADR-206).
 */
final class LeavePolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'hr.staff.read')
            || $this->coarse($user, 'hr.leave.request')
            || $this->coarse($user, 'hr.leave.approve')
            || $this->coarse($user, 'hr.staff.manage');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'hr.leave_type.manage') || $this->coarse($user, 'hr.staff.manage');
    }

    public function post(User $user): bool
    {
        return $this->coarse($user, 'hr.leave_ledger.post') || $this->coarse($user, 'hr.staff.manage');
    }

    public function request(User $user): bool
    {
        return $this->coarse($user, 'hr.leave.request') || $this->coarse($user, 'hr.staff.manage');
    }

    public function approve(User $user): bool
    {
        return $this->coarse($user, 'hr.leave.approve') || $this->coarse($user, 'hr.staff.manage');
    }

    public function reject(User $user): bool
    {
        return $this->coarse($user, 'hr.leave.approve') || $this->coarse($user, 'hr.staff.manage');
    }

    public function cancel(User $user): bool
    {
        return $this->coarse($user, 'hr.leave.request')
            || $this->coarse($user, 'hr.leave.approve')
            || $this->coarse($user, 'hr.staff.manage');
    }

    public function confirm(User $user): bool
    {
        return $this->coarse($user, 'hr.leave.approve') || $this->coarse($user, 'hr.staff.manage');
    }

    public function confirmTaken(User $user): bool
    {
        return $this->confirm($user);
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
