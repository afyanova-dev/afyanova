<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Authorization;

use App\Models\User;
use App\Platform\Notifications\Domain\Model\InAppNotification;
use App\Shared\Authorization\BasePolicy;

final class InAppNotificationPolicy extends BasePolicy
{
    public function view(User $user, ?InAppNotification $notification = null): bool
    {
        if ($notification !== null) {
            return $this->sameTenant($user, (string) $notification->tenant_id)
                && $user->id === $notification->user_id;
        }

        return $this->context->hasTenant();
    }

    public function markRead(User $user, InAppNotification $notification): bool
    {
        return $this->sameTenant($user, (string) $notification->tenant_id)
            && $user->id === $notification->user_id;
    }
}
