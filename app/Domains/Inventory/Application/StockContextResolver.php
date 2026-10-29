<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application;

use App\Domains\Inventory\Contracts\Dto\ItemSummary;
use App\Domains\Inventory\Contracts\Dto\StockLocationSummary;
use App\Domains\Inventory\Domain\Exception\InventoryItemNotStockable;
use App\Domains\Inventory\Domain\Exception\StockLocationNotUsable;
use App\Domains\Inventory\Domain\ItemReader;
use App\Domains\Inventory\Domain\ItemRepository;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\StockLocationReader;
use App\Domains\Inventory\Domain\StockLocationRepository;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Shared resolution + per-facility authorization refinement for the Inventory
 * stock-movement handlers (ADR-170 D-L / D-O — the CreateImagingOrderHandler
 * per-encounter-facility refinement precedent). Catalog rows are locked before
 * checking active state so lifecycle changes serialize with stock movements.
 */
final class StockContextResolver
{
    public function __construct(
        private readonly ItemReader $items,
        private readonly ItemRepository $itemRecords,
        private readonly StockLocationReader $locations,
        private readonly StockLocationRepository $locationRecords,
        private readonly ScopeChecker $scope,
    ) {}

    /** Throws `NotFound` if unknown, `InventoryItemNotStockable` if retired. */
    public function resolveActiveItem(string $itemId): ItemSummary
    {
        $this->itemRecords->getByIdForUpdate($itemId);
        $item = $this->items->summary($itemId); // NotFound if unknown in tenant

        if (! $item->active) {
            throw InventoryItemNotStockable::retired($itemId);
        }

        return $item;
    }

    /** Throws `NotFound` if unknown, `StockLocationNotUsable` if retired. */
    public function resolveActiveLocation(string $locationId): StockLocationSummary
    {
        $this->locationRecords->getByIdForUpdate($locationId);
        $location = $this->locations->summary($locationId); // NotFound if unknown in tenant

        if (! $location->active) {
            throw StockLocationNotUsable::retired($locationId);
        }

        return $location;
    }

    /**
     * ADR-133 per-facility refinement: the actor must hold `$permissionKey` at
     * `$facilityId`, or the aggregate is `NotFound` (never an authz disclosure).
     */
    public function assertActorScopedToFacility(string $facilityId, string $permissionKey, string $subjectId): void
    {
        if (! $this->actorHasPermission($facilityId, $permissionKey)) {
            throw NotFound::aggregate(StockBalance::class, $subjectId);
        }
    }

    /**
     * Does the signed-in actor hold `$permissionKey` at `$facilityId`?
     *
     * Used where a command does optional extra work (a count raising its own
     * corrections) and must degrade honestly instead of failing the whole job.
     */
    public function actorHasPermission(string $facilityId, string $permissionKey): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('Inventory command dispatched without an authenticated user (IC §5).');
        }

        return in_array($permissionKey, $this->scope->effectivePermissions($user, $facilityId), true);
    }
}
