<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Authorization;

use App\Domains\BedWard\Application\Command\CreateRoom;
use App\Domains\BedWard\Application\Command\CreateWard;
use App\Domains\BedWard\Application\Command\DecommissionWard;
use App\Domains\BedWard\Application\Command\UpdateWard;
use App\Domains\BedWard\Domain\Model\Ward;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Ward aggregate policy (ADR-147 D-I, ADR-148 D-G).
 */
final class WardPolicy extends BasePolicy
{
    public function create(User $user, ?CreateWard $command = null): bool
    {
        if (! $this->context->hasTenant() || (string) $user->tenant_id !== $this->context->tenantId()) {
            return false;
        }

        if ($command !== null) {
            return in_array(
                'bed_ward.wards.configure',
                $this->scope->effectivePermissions($user, $command->facilityId),
                true,
            );
        }

        return $this->permits($user, 'bed_ward.wards.configure');
    }

    public function update(User $user, Ward $ward, ?UpdateWard $command = null): bool
    {
        return $this->sameTenant($user, (string) $ward->tenant_id)
            && in_array(
                'bed_ward.wards.configure',
                $this->scope->effectivePermissions($user, (string) $ward->facility_id),
                true,
            );
    }

    public function decommission(User $user, Ward $ward, ?DecommissionWard $command = null): bool
    {
        return $this->sameTenant($user, (string) $ward->tenant_id)
            && in_array(
                'bed_ward.wards.configure',
                $this->scope->effectivePermissions($user, (string) $ward->facility_id),
                true,
            );
    }

    public function createRoom(User $user, Ward $ward, ?CreateRoom $command = null): bool
    {
        return $this->sameTenant($user, (string) $ward->tenant_id)
            && in_array(
                'bed_ward.wards.configure',
                $this->scope->effectivePermissions($user, (string) $ward->facility_id),
                true,
            );
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'bed_ward.access');
    }
}
