<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Authorization;

use App\Domains\Scheduling\Domain\Model\Slot;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

final class SlotPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'sch.access');
    }

    public function generate(User $user): bool
    {
        return $this->coarse($user, 'sch.slot.generate');
    }

    public function hold(User $user, Slot $slot): bool
    {
        return $this->sameTenant($user, (string) $slot->tenant_id)
            && $this->permits($user, 'sch.slot.hold');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
