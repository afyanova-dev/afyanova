<?php

declare(strict_types=1);

namespace App\Domains\HR\Authorization;

use App\Domains\HR\Domain\Model\Person;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Authorization policy for Person aggregate (ADR-206).
 */
final class PersonPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'hr.staff.read') || $this->coarse($user, 'hr.person.manage');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'hr.person.manage');
    }

    public function create(User $user): bool
    {
        return $this->coarse($user, 'hr.person.manage');
    }

    public function update(User $user, Person $person): bool
    {
        return $this->coarse($user, 'hr.person.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
