<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Authorization;

use App\Models\User;
use App\Platform\Notifications\Domain\Model\NotificationRequest;
use App\Shared\Authorization\BasePolicy;

final class NotificationPolicy extends BasePolicy
{
    public function view(User $user, ?NotificationRequest $request = null): bool
    {
        if ($request !== null) {
            return $this->sameTenant($user, (string) $request->tenant_id)
                && $this->permits($user, 'notifications.delivery.view');
        }

        return $this->coarse($user, 'notifications.delivery.view');
    }

    public function send(User $user): bool
    {
        return $this->coarse($user, 'notifications.message.send');
    }

    public function bulk(User $user): bool
    {
        return $this->coarse($user, 'notifications.message.bulk');
    }

    public function manageTemplate(User $user): bool
    {
        return $this->coarse($user, 'notifications.template.manage');
    }

    public function managePreference(User $user): bool
    {
        return $this->coarse($user, 'notifications.preference.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && $this->permits($user, $key);
    }
}
