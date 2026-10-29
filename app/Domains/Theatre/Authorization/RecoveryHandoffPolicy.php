<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Authorization;

use App\Domains\Theatre\Application\Command\AcknowledgeRecoveryHandoff;
use App\Domains\Theatre\Application\Command\InitiateRecoveryHandoff;
use App\Domains\Theatre\Domain\Model\RecoveryHandoff;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * RecoveryHandoff aggregate policy (ADR-156 D-D). Two keys:
 *   theatre.recovery.handoff     → create (initiate)
 *   theatre.recovery.acknowledge → acknowledge (the receiving side)
 */
final class RecoveryHandoffPolicy extends BasePolicy
{
    public function create(User $user, ?InitiateRecoveryHandoff $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'theatre.recovery.handoff');
    }

    public function acknowledge(User $user, RecoveryHandoff $handoff, ?AcknowledgeRecoveryHandoff $command = null): bool
    {
        return $this->sameTenant($user, (string) $handoff->tenant_id)
            && in_array(
                'theatre.recovery.acknowledge',
                $this->scope->effectivePermissions($user, (string) $handoff->facility_id),
                true,
            );
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'theatre.access');
    }
}
