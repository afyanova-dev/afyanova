<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Authorization;

use App\Domains\Inventory\Application\Command\DefineInventoryItem;
use App\Domains\Inventory\Application\Command\DefineStockLocation;
use App\Domains\Inventory\Application\Command\DefineStockLocationType;
use App\Domains\Inventory\Application\Command\SetInventoryItemActive;
use App\Domains\Inventory\Application\Command\SetItemLevel;
use App\Domains\Inventory\Application\Command\SetStockLocationActive;
use App\Domains\Inventory\Domain\Model\Item;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

final class InventoryCatalogPolicy extends BasePolicy
{
    public function manageItems(User $user, DefineInventoryItem|SetInventoryItemActive|null $command = null): bool
    {
        return $this->context->hasTenant()
            && $this->context->isGeneral()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'inventory.items.manage');
    }

    /**
     * Setting a minimum level is a FACILITY decision even though the item
     * master is tenant-wide: the same item is reordered at different scales at
     * different facilities. The item must belong to the actor's tenant and the
     * command must run in the active facility.
     */
    public function manageItemLevels(User $user, ?Item $item = null, ?SetItemLevel $command = null): bool
    {
        return $this->context->hasTenant()
            && $this->context->facilityId() !== null
            && (string) $user->tenant_id === $this->context->tenantId()
            && ($item === null || $this->sameTenant($user, (string) $item->tenant_id))
            && ($command === null || $this->inActiveFacility($command->facilityId))
            && $this->permits($user, 'inventory.items.manage');
    }

    public function manageLocations(User $user, DefineStockLocation|SetStockLocationActive|null $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->context->facilityId() !== null
            && ($command === null || $this->inActiveFacility($command->facilityId))
            && $this->permits($user, 'inventory.locations.manage');
    }

    public function manageLocationTypes(User $user, ?DefineStockLocationType $command = null): bool
    {
        $targetFacilityId = $command?->locationTypeFacilityId;
        $scopeMatches = $command === null
            || ($this->context->isGeneral()
                ? $targetFacilityId === null
                : $this->inActiveFacility((string) $targetFacilityId));

        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && ($this->context->isGeneral() || $this->context->facilityId() !== null)
            && $scopeMatches
            && $this->permits($user, 'inventory.location_types.manage');
    }
}
