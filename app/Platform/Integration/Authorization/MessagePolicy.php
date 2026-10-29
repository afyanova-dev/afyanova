<?php

declare(strict_types=1);

namespace App\Platform\Integration\Authorization;

use App\Models\User;
use App\Platform\Integration\Domain\Model\InboundMessage;
use App\Platform\Integration\Domain\Model\OutboundMessage;
use App\Shared\Authorization\BasePolicy;

final class MessagePolicy extends BasePolicy
{
    public function view(User $user, InboundMessage|OutboundMessage|null $message = null): bool
    {
        if ($message !== null) {
            return $this->sameTenant($user, (string) $message->tenant_id)
                && $this->permits($user, 'integration.messages.view');
        }

        return $this->coarse($user, 'integration.messages.view');
    }

    public function resolveDeadLetter(User $user, InboundMessage|OutboundMessage|null $message = null): bool
    {
        if ($message !== null) {
            return $this->sameTenant($user, (string) $message->tenant_id)
                && $this->permits($user, 'integration.deadletter.resolve');
        }

        return $this->coarse($user, 'integration.deadletter.resolve');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && $this->permits($user, $key);
    }
}
