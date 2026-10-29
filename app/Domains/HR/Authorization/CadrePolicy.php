<?php

declare(strict_types=1);

namespace App\Domains\HR\Authorization;

use App\Domains\HR\Domain\Model\Cadre;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Authorization policy for Cadre master (ADR-206).
 */
final class CadrePolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'hr.access');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'hr.cadre_position.manage');
    }

    public function create(User $user): bool
    {
        return $this->coarse($user, 'hr.cadre_position.manage');
    }

    public function update(User $user, Cadre $cadre): bool
    {
        return $this->coarse($user, 'hr.cadre_position.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
