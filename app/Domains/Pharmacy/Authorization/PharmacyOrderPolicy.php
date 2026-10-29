<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Authorization;

use App\Domains\Pharmacy\Application\Command\CancelPharmacyOrder;
use App\Domains\Pharmacy\Application\Command\CancelPickup;
use App\Domains\Pharmacy\Application\Command\CreatePharmacyOrder;
use App\Domains\Pharmacy\Application\Command\Dispense;
use App\Domains\Pharmacy\Application\Command\HoldPharmacyOrder;
use App\Domains\Pharmacy\Application\Command\MarkPickupNotCollected;
use App\Domains\Pharmacy\Application\Command\RecordPickup;
use App\Domains\Pharmacy\Application\Command\RejectPharmacyOrder;
use App\Domains\Pharmacy\Application\Command\ReleasePharmacyOrderHold;
use App\Domains\Pharmacy\Application\Command\ReverseDispensing;
use App\Domains\Pharmacy\Application\Command\ValidatePharmacyOrder;
use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Pharmacy Order policy (ADR-182 D-E).
 *
 * `view` is a coarse `pharmacy.access` check (`SearchPharmacyOrders` re-checks
 * the acting user's scope at the facility). `create` is coarse
 * `pharmacy.orders.manage` (the handler's ADR-133 refinement at the
 * prescription's facility is the real gate). `manage` is per-subject —
 * `pharmacy.orders.manage` at the order's facility (validate / reject / hold /
 * release / cancel) — the `StockAdjustmentPolicy` shape. `dispense` (ADR-184)
 * is per-subject `pharmacy.dispensing.record` at the order's facility.
 * `reverse` (ADR-185) is per-subject `pharmacy.dispensing.reverse` — a distinct
 * higher-trust key (§110 "high-risk overrides must be audited"). `pickup`
 * (ADR-186) is per-subject `pharmacy.pickup.record` at the order's facility.
 */
final class PharmacyOrderPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'pharmacy.access');
    }

    public function create(User $user, ?CreatePharmacyOrder $command = null): bool
    {
        return $this->coarse($user, 'pharmacy.orders.manage');
    }

    public function manage(
        User $user,
        PharmacyOrder $order,
        ValidatePharmacyOrder|RejectPharmacyOrder|HoldPharmacyOrder|ReleasePharmacyOrderHold|CancelPharmacyOrder|null $command = null,
    ): bool {
        return $this->sameTenant($user, (string) $order->tenant_id)
            && in_array(
                'pharmacy.orders.manage',
                $this->scope->effectivePermissions($user, (string) $order->facility_id),
                true,
            );
    }

    public function dispense(User $user, PharmacyOrder $order, ?Dispense $command = null): bool
    {
        return $this->sameTenant($user, (string) $order->tenant_id)
            && in_array(
                'pharmacy.dispensing.record',
                $this->scope->effectivePermissions($user, (string) $order->facility_id),
                true,
            );
    }

    public function reverse(User $user, PharmacyOrder $order, ?ReverseDispensing $command = null): bool
    {
        return $this->sameTenant($user, (string) $order->tenant_id)
            && in_array(
                'pharmacy.dispensing.reverse',
                $this->scope->effectivePermissions($user, (string) $order->facility_id),
                true,
            );
    }

    public function pickup(
        User $user,
        PharmacyOrder $order,
        RecordPickup|MarkPickupNotCollected|CancelPickup|null $command = null,
    ): bool {
        return $this->sameTenant($user, (string) $order->tenant_id)
            && in_array(
                'pharmacy.pickup.record',
                $this->scope->effectivePermissions($user, (string) $order->facility_id),
                true,
            );
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
