<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Authorization;

use App\Domains\BedWard\Application\Command\AssignBed;
use App\Domains\BedWard\Application\Command\BlockBed;
use App\Domains\BedWard\Application\Command\CreateBed;
use App\Domains\BedWard\Application\Command\DecommissionBed;
use App\Domains\BedWard\Application\Command\MarkBedCleaning;
use App\Domains\BedWard\Application\Command\MarkBedReady;
use App\Domains\BedWard\Application\Command\RequestBedReservation;
use App\Domains\BedWard\Application\Command\TransferBed;
use App\Domains\BedWard\Application\Command\UnblockBed;
use App\Domains\BedWard\Application\Command\UpdateBed;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Bed aggregate policy (ADR-147 D-I, ADR-148 D-G, ADR-149 D-F).
 */
final class BedPolicy extends BasePolicy
{
    public function create(User $user, ?CreateBed $command = null): bool
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

    public function update(User $user, Bed $bed, ?UpdateBed $command = null): bool
    {
        return $this->sameTenant($user, (string) $bed->tenant_id)
            && in_array(
                'bed_ward.wards.configure',
                $this->scope->effectivePermissions($user, (string) $bed->facility_id),
                true,
            );
    }

    public function decommission(User $user, Bed $bed, ?DecommissionBed $command = null): bool
    {
        return $this->sameTenant($user, (string) $bed->tenant_id)
            && in_array(
                'bed_ward.wards.configure',
                $this->scope->effectivePermissions($user, (string) $bed->facility_id),
                true,
            );
    }

    public function block(User $user, Bed $bed, ?BlockBed $command = null): bool
    {
        return $this->sameTenant($user, (string) $bed->tenant_id)
            && in_array(
                'bed_ward.beds.block',
                $this->scope->effectivePermissions($user, (string) $bed->facility_id),
                true,
            );
    }

    public function unblock(User $user, Bed $bed, ?UnblockBed $command = null): bool
    {
        return $this->sameTenant($user, (string) $bed->tenant_id)
            && in_array(
                'bed_ward.beds.block',
                $this->scope->effectivePermissions($user, (string) $bed->facility_id),
                true,
            );
    }

    public function markCleaning(User $user, Bed $bed, ?MarkBedCleaning $command = null): bool
    {
        return $this->sameTenant($user, (string) $bed->tenant_id)
            && in_array(
                'bed_ward.beds.release',
                $this->scope->effectivePermissions($user, (string) $bed->facility_id),
                true,
            );
    }

    public function markReady(User $user, Bed $bed, ?MarkBedReady $command = null): bool
    {
        return $this->sameTenant($user, (string) $bed->tenant_id)
            && in_array(
                'bed_ward.beds.release',
                $this->scope->effectivePermissions($user, (string) $bed->facility_id),
                true,
            );
    }

    public function assign(User $user, Bed $bed, ?AssignBed $command = null): bool
    {
        return $this->sameTenant($user, (string) $bed->tenant_id)
            && in_array(
                'bed_ward.beds.assign',
                $this->scope->effectivePermissions($user, (string) $bed->facility_id),
                true,
            );
    }

    public function transfer(User $user, Bed $bed, ?TransferBed $command = null): bool
    {
        return $this->sameTenant($user, (string) $bed->tenant_id)
            && in_array(
                'bed_ward.beds.assign',
                $this->scope->effectivePermissions($user, (string) $bed->facility_id),
                true,
            );
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'bed_ward.access');
    }

    /**
     * RequestBedReservation authorizes against the target Bed — the
     * reservation itself does not exist yet (ADR-149 D-A/D-F).
     */
    public function request(User $user, Bed $bed, ?RequestBedReservation $command = null): bool
    {
        return $this->sameTenant($user, (string) $bed->tenant_id)
            && in_array(
                'bed_ward.reservations.manage',
                $this->scope->effectivePermissions($user, (string) $bed->facility_id),
                true,
            );
    }
}
