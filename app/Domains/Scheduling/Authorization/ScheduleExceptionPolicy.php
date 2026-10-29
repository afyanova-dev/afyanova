<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

final class ScheduleExceptionPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'sch.access');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'sch.schedule.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
