<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Authorization;

use App\Domains\Theatre\Application\Command\RecordCaseEvent;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * CaseEvent aggregate policy (ADR-156 D-E). One key —
 * `theatre.case_events.record`. Append-only: only `create` and `view`.
 */
final class CaseEventPolicy extends BasePolicy
{
    public function create(User $user, ?RecordCaseEvent $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'theatre.case_events.record');
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'theatre.access');
    }
}
