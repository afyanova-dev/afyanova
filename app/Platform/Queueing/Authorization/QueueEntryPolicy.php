<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Authorization;

use App\Models\User;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Shared\Authorization\BasePolicy;

final class QueueEntryPolicy extends BasePolicy
{
    public function view(User $user, QueueEntry $entry): bool
    {
        return $this->sameTenant($user, (string) $entry->tenant_id)
            && $this->permits($user, 'queue.ticket.view');
    }

    public function call(User $user, QueueEntry $entry): bool
    {
        return $this->sameTenant($user, (string) $entry->tenant_id)
            && $this->inActiveFacility((string) $entry->facility_id)
            && $this->permits($user, 'queue.ticket.call');
    }

    public function service(User $user, QueueEntry $entry): bool
    {
        return $this->sameTenant($user, (string) $entry->tenant_id)
            && $this->inActiveFacility((string) $entry->facility_id)
            && $this->permits($user, 'queue.ticket.service');
    }

    public function hold(User $user, QueueEntry $entry): bool
    {
        return $this->sameTenant($user, (string) $entry->tenant_id)
            && $this->inActiveFacility((string) $entry->facility_id)
            && $this->permits($user, 'queue.ticket.hold');
    }

    public function transfer(User $user, QueueEntry $entry): bool
    {
        return $this->sameTenant($user, (string) $entry->tenant_id)
            && $this->inActiveFacility((string) $entry->facility_id)
            && $this->permits($user, 'queue.ticket.transfer');
    }

    public function skip(User $user, QueueEntry $entry): bool
    {
        return $this->sameTenant($user, (string) $entry->tenant_id)
            && $this->inActiveFacility((string) $entry->facility_id)
            && ($this->permits($user, 'queue.ticket.skip') || $this->permits($user, 'queue.ticket.call'));
    }
}
