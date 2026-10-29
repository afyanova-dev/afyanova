<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Authorization;

use App\Domains\Inventory\Application\Command\AddStockCountLine;
use App\Domains\Inventory\Application\Command\CloseStockCount;
use App\Domains\Inventory\Application\Command\SaveStockCountCounts;
use App\Domains\Inventory\Application\Command\StartStockCount;
use App\Domains\Inventory\Domain\Model\StockCount;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * StockCount policy (Phase 8, stock counts).
 *
 * `create` starts a count — a coarse, any-facility check; the handler's own
 * ADR-133 per-facility refinement (`inventory.counts.manage`) enforces the real
 * scope. `manage` is per-subject: recording counts, adding a line and closing
 * the count all act on one count, so the actor must hold
 * `inventory.counts.manage` at that count's facility. Reads go through the
 * `StockBalancePolicy::view` key like every other Inventory read.
 */
final class StockCountPolicy extends BasePolicy
{
    public function create(User $user, ?StartStockCount $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'inventory.counts.manage');
    }

    public function manage(
        User $user,
        StockCount $count,
        SaveStockCountCounts|AddStockCountLine|CloseStockCount|null $command = null,
    ): bool {
        return $this->sameTenant($user, (string) $count->tenant_id)
            && in_array('inventory.counts.manage', $this->scope->effectivePermissions($user, (string) $count->facility_id), true);
    }
}
