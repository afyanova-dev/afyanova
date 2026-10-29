<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Authorization;

use App\Domains\Scheduling\Domain\Model\WaitlistEntry;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

final class WaitlistPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'sch.access');
    }

    public function manage(User $user, ?WaitlistEntry $entry = null): bool
    {
        if ($entry === null) {
            return $this->coarse($user, 'sch.waitlist.manage');
        }

        return $this->sameTenant($user, (string) $entry->tenant_id)
            && $this->permits($user, 'sch.waitlist.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
