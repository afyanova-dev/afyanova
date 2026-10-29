<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Authorization;

use App\Domains\BedWard\Application\Command\CancelBedReservation;
use App\Domains\BedWard\Application\Command\HoldBedReservation;
use App\Domains\BedWard\Domain\Model\BedReservation;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * BedReservation aggregate policy (ADR-149 D-F). `RequestBedReservation`
 * authorizes against the target Bed instead — see BedPolicy::request()
 * (ADR-149 D-A: the bed already exists at request time, the reservation
 * does not yet).
 */
final class BedReservationPolicy extends BasePolicy
{
    public function hold(User $user, BedReservation $reservation, ?HoldBedReservation $command = null): bool
    {
        return $this->sameTenant($user, (string) $reservation->tenant_id)
            && in_array(
                'bed_ward.reservations.manage',
                $this->scope->effectivePermissions($user, (string) $reservation->facility_id),
                true,
            );
    }

    public function cancel(User $user, BedReservation $reservation, ?CancelBedReservation $command = null): bool
    {
        return $this->sameTenant($user, (string) $reservation->tenant_id)
            && in_array(
                'bed_ward.reservations.manage',
                $this->scope->effectivePermissions($user, (string) $reservation->facility_id),
                true,
            );
    }
}
