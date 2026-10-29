<?php

declare(strict_types=1);

namespace App\Platform\Integration\Authorization;

use App\Models\User;
use App\Platform\Integration\Domain\Model\IdMapping;
use App\Shared\Authorization\BasePolicy;

final class MappingPolicy extends BasePolicy
{
    public function manage(User $user, ?IdMapping $mapping = null): bool
    {
        if ($mapping !== null) {
            return $this->sameTenant($user, (string) $mapping->tenant_id)
                && $this->permits($user, 'integration.mapping.manage');
        }

        return $this->coarse($user, 'integration.mapping.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && $this->permits($user, $key);
    }
}
