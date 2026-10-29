<?php

declare(strict_types=1);

namespace App\Domains\HR\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Authorization policy for Assignment aggregate root (ADR-206).
 */
final class AssignmentPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'hr.staff.read') || $this->coarse($user, 'hr.assignment.manage');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'hr.assignment.manage');
    }

    public function create(User $user): bool
    {
        return $this->coarse($user, 'hr.assignment.manage')
            || ($this->coarse($user, 'admin.workspace.access')
                && $this->coarse($user, 'identity.assignments.manage'));
    }

    public function amend(User $user): bool
    {
        return $this->coarse($user, 'hr.assignment.manage');
    }

    public function end(User $user): bool
    {
        return $this->coarse($user, 'hr.assignment.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
