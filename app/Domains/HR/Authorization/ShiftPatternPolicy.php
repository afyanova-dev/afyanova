<?php

declare(strict_types=1);

namespace App\Domains\HR\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Authorization policy for ShiftPattern aggregate root (ADR-206).
 */
final class ShiftPatternPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'hr.staff.read') || $this->coarse($user, 'hr.shift_pattern.manage');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'hr.shift_pattern.manage');
    }

    public function create(User $user): bool
    {
        return $this->coarse($user, 'hr.shift_pattern.manage');
    }

    public function update(User $user): bool
    {
        return $this->coarse($user, 'hr.shift_pattern.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
