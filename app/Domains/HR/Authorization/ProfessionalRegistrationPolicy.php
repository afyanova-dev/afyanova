<?php

declare(strict_types=1);

namespace App\Domains\HR\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Authorization policy for ProfessionalRegistration aggregate root (ADR-206).
 */
final class ProfessionalRegistrationPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'hr.staff.read') || $this->coarse($user, 'hr.registration.manage');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'hr.registration.manage');
    }

    public function create(User $user): bool
    {
        return $this->coarse($user, 'hr.registration.manage');
    }

    public function updateStatus(User $user): bool
    {
        return $this->coarse($user, 'hr.registration.manage');
    }

    public function recordVerification(User $user): bool
    {
        return $this->coarse($user, 'hr.registration.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
