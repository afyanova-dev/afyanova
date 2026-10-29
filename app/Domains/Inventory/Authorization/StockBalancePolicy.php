<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Authorization;

use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Inventory\Application\Command\RecordConsumption;
use App\Domains\Inventory\Application\Command\RecordGoodsReceipt;
use App\Domains\Inventory\Application\Command\ReturnGoodsToSupplier;
use App\Domains\Inventory\Application\Command\ReverseConsumption;
use App\Domains\Inventory\Application\Command\TransferStock;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * StockBalance policy (ADR-170 D-O / D-P).
 *
 * `receive` / `consume` are the stock-movement command abilities — coarse,
 * any-facility checks; each handler's own ADR-133 per-facility refinement
 * (`StockContextResolver::assertActorScopedToFacility`) enforces the real
 * scope. `view` gates every `#[ProtectedQuery(subjectType: StockBalance::class)]`
 * in the module — the catalog reads AND the balance / ledger reads (the CP.1 /
 * Lab.1 / Rad.1 route-catalog-reads-through-one-policy precedent); the balance /
 * ledger handlers add a `ScopeChecker` facility refinement of their own.
 */
final class StockBalancePolicy extends BasePolicy
{
    public function receive(User $user, ReceiveStock|RecordGoodsReceipt|null $command = null): bool
    {
        return $this->coarse($user, 'inventory.receipts.create');
    }

    public function consume(User $user, RecordConsumption|ReverseConsumption|null $command = null): bool
    {
        return $this->coarse($user, 'inventory.consumption.record');
    }

    /**
     * Moving stock between two locations of one facility (Phase 8). Coarse
     * here; the handler checks the key at the source location's facility and
     * refuses a cross-facility move outright.
     */
    public function transfer(User $user, ?TransferStock $command = null): bool
    {
        return $this->coarse($user, 'inventory.transfers.create');
    }

    public function returnToSupplier(User $user, ?ReturnGoodsToSupplier $command = null): bool
    {
        return $this->coarse($user, 'inventory.receipts.create');
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
