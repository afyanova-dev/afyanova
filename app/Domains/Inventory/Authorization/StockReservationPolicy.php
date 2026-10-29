<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Authorization;

use App\Domains\Inventory\Application\Command\CancelStockReservation;
use App\Domains\Inventory\Application\Command\ReleaseStockReservation;
use App\Domains\Inventory\Application\Command\ReserveStock;
use App\Domains\Inventory\Domain\Model\StockReservation;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * StockReservation policy (ADR-180 D-G).
 *
 * `reserve` is a coarse, any-facility check (the handler's ADR-133 refinement
 * enforces the real scope). `manage` is per-subject — the actor must hold
 * `inventory.reservations.manage` at the reservation's facility (release /
 * cancel). `view` gates `GetStockReservation` / `SearchStockReservations`.
 * `ExpireStockReservation` is system-only — never reaches this policy.
 */
final class StockReservationPolicy extends BasePolicy
{
    public function reserve(User $user, ?ReserveStock $command = null): bool
    {
        return $this->coarse($user, 'inventory.reservations.manage');
    }

    public function manage(
        User $user,
        StockReservation $reservation,
        ReleaseStockReservation|CancelStockReservation|null $command = null,
    ): bool {
        return $this->sameTenant($user, (string) $reservation->tenant_id)
            && in_array(
                'inventory.reservations.manage',
                $this->scope->effectivePermissions($user, (string) $reservation->facility_id),
                true,
            );
    }

    public function view(User $user): bool
    {
        return $this->coarse($user, 'inventory.access');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
