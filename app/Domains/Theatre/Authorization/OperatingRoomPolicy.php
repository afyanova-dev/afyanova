<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Authorization;

use App\Domains\Theatre\Application\Command\AssignOperatingRoom;
use App\Domains\Theatre\Application\Command\CreateOperatingRoom;
use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * OperatingRoom aggregate policy (ADR-150 D-A/D-I).
 */
final class OperatingRoomPolicy extends BasePolicy
{
    public function create(User $user, ?CreateOperatingRoom $command = null): bool
    {
        if (! $this->context->hasTenant() || (string) $user->tenant_id !== $this->context->tenantId()) {
            return false;
        }

        if ($command !== null) {
            return in_array(
                'theatre.rooms.manage',
                $this->scope->effectivePermissions($user, $command->facilityId),
                true,
            );
        }

        return $this->permits($user, 'theatre.rooms.manage');
    }

    public function assign(User $user, OperatingRoom $room, ?AssignOperatingRoom $command = null): bool
    {
        return $this->sameTenant($user, (string) $room->tenant_id)
            && in_array(
                'theatre.rooms.manage',
                $this->scope->effectivePermissions($user, (string) $room->facility_id),
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
