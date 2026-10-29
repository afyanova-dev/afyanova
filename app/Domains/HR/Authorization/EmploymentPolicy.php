<?php

declare(strict_types=1);

namespace App\Domains\HR\Authorization;

use App\Domains\HR\Domain\Model\Employment;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Authorization policy for Employment aggregate root (ADR-206).
 */
final class EmploymentPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'hr.staff.read') || $this->coarse($user, 'hr.employment.manage');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'hr.employment.manage');
    }

    public function create(User $user): bool
    {
        return $this->coarse($user, 'hr.employment.manage');
    }

    public function start(User $user, Employment $employment): bool
    {
        return $this->coarse($user, 'hr.employment.manage');
    }

    public function suspend(User $user, Employment $employment): bool
    {
        return $this->coarse($user, 'hr.employment.manage');
    }

    public function reinstate(User $user, Employment $employment): bool
    {
        return $this->coarse($user, 'hr.employment.manage');
    }

    public function amend(User $user, Employment $employment): bool
    {
        return $this->coarse($user, 'hr.employment.manage');
    }

    public function end(User $user, Employment $employment): bool
    {
        return $this->coarse($user, 'hr.employment.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
