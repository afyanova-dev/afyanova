<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Authorization;

use App\Domains\BedWard\Application\Command\CorrectOccupancy;
use App\Domains\BedWard\Application\Command\ReleaseBed;
use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * BedOccupancy aggregate policy (ADR-147 D-I, ADR-148 D-G/D-H).
 */
final class BedOccupancyPolicy extends BasePolicy
{
    public function release(User $user, BedOccupancy $occupancy, ?ReleaseBed $command = null): bool
    {
        return $this->sameTenant($user, (string) $occupancy->tenant_id)
            && in_array(
                'bed_ward.beds.release',
                $this->scope->effectivePermissions($user, (string) $occupancy->facility_id),
                true,
            );
    }

    public function correct(User $user, BedOccupancy $occupancy, ?CorrectOccupancy $command = null): bool
    {
        return $this->sameTenant($user, (string) $occupancy->tenant_id)
            && in_array(
                'bed_ward.occupancy.correct',
                $this->scope->effectivePermissions($user, (string) $occupancy->facility_id),
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
