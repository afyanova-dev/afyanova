<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Contracts\Dto\ItemLevelSummary;
use App\Domains\Inventory\Domain\Model\ItemLevel;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Quantity;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetItemLevelsHandler
{
    public function __construct(private readonly ScopeChecker $scope) {}

    /**
     * @return list<ItemLevelSummary>
     */
    public function __invoke(GetItemLevels $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetItemLevels dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermission($user, $query->facilityId, 'inventory.access')) {
            throw NotFound::aggregate(StockBalance::class, $query->facilityId);
        }

        $levels = ItemLevel::query()
            // Only active items can be tracked or edited; a retired item keeps
            // its row (it applies again if reactivated) but is not shown.
            ->select('inv_item_levels.*')
            ->join('inv_items', 'inv_items.id', '=', 'inv_item_levels.item_id')
            ->where('inv_item_levels.facility_id', $query->facilityId)
            ->where('inv_items.active', true)
            ->orderBy('inv_items.code')
            ->get();

        /** @var list<ItemLevelSummary> $out */
        $out = [];
        foreach ($levels as $level) {
            $out[] = new ItemLevelSummary(
                itemId: (string) $level->item_id,
                minLevel: Quantity::of((string) $level->min_level),
            );
        }

        return $out;
    }
}
