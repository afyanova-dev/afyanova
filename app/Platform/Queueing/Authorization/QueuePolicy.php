<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Authorization;

use App\Models\User;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Shared\Authorization\BasePolicy;

final class QueuePolicy extends BasePolicy
{
    public function view(User $user, ?Queue $queue = null): bool
    {
        if ($queue !== null) {
            return $this->sameTenant($user, (string) $queue->tenant_id)
                && $this->permits($user, 'queue.ticket.view');
        }

        return $this->coarse($user, 'queue.access');
    }

    public function manage(User $user, ?Queue $queue = null): bool
    {
        if ($queue !== null) {
            return $this->sameTenant($user, (string) $queue->tenant_id)
                && $this->inActiveFacility((string) $queue->facility_id)
                && $this->permits($user, 'queue.definition.manage');
        }

        return $this->coarse($user, 'queue.definition.manage');
    }

    public function place(User $user): bool
    {
        return $this->coarse($user, 'queue.ticket.place');
    }

    public function placeIn(User $user, Queue $queue): bool
    {
        return $this->sameTenant($user, (string) $queue->tenant_id)
            && $this->inActiveFacility((string) $queue->facility_id)
            && $this->permits($user, 'queue.ticket.place');
    }

    public function register(User $user): bool
    {
        return $this->coarse($user, 'queue.definition.manage');
    }

    public function call(User $user): bool
    {
        return $this->coarse($user, 'queue.ticket.call');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
