<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Authorization;

use App\Domains\Inventory\Application\Command\ApproveStockAdjustment;
use App\Domains\Inventory\Application\Command\CreateStockAdjustment;
use App\Domains\Inventory\Application\Command\RejectStockAdjustment;
use App\Domains\Inventory\Domain\Model\StockAdjustment;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * StockAdjustment policy (ADR-170 D-O / D-P).
 *
 * `create` is a coarse, any-facility check (the handler's ADR-133 refinement
 * enforces the real scope). `approve` / `reject` are per-subject — the actor
 * must hold `inventory.adjustments.approve` at the adjustment's facility.
 * `view` gates `GetStockAdjustments`.
 */
final class StockAdjustmentPolicy extends BasePolicy
{
    public function create(User $user, ?CreateStockAdjustment $command = null): bool
    {
        return $this->coarse($user, 'inventory.adjustments.create');
    }

    public function approve(User $user, StockAdjustment $adjustment, ?ApproveStockAdjustment $command = null): bool
    {
        return $this->hasScope($user, $adjustment, 'inventory.adjustments.approve');
    }

    public function reject(User $user, StockAdjustment $adjustment, ?RejectStockAdjustment $command = null): bool
    {
        return $this->hasScope($user, $adjustment, 'inventory.adjustments.approve');
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

    private function hasScope(User $user, StockAdjustment $adjustment, string $key): bool
    {
        return $this->sameTenant($user, (string) $adjustment->tenant_id)
            && in_array($key, $this->scope->effectivePermissions($user, (string) $adjustment->facility_id), true);
    }
}
