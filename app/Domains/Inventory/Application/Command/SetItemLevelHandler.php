<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Domain\ItemRepository;
use App\Domains\Inventory\Domain\Model\ItemLevel;
use App\Domains\Inventory\Domain\Quantity;

final class SetItemLevelHandler
{
    public function __construct(private readonly ItemRepository $items) {}

    public function __invoke(SetItemLevel $command): void
    {
        // RLS + the command policy already proved the item belongs to this
        // tenant. Loading it here keeps that proof explicit and fails the same
        // way every other item command does.
        $this->items->getById($command->itemId);

        $existing = ItemLevel::query()
            ->where('facility_id', $command->facilityId)
            ->where('item_id', $command->itemId)
            ->first();

        if (Quantity::compare($command->minLevel, '0') <= 0) {
            $existing?->delete();

            return;
        }

        if ($existing === null) {
            // `tenant_id` is filled by the BelongsToTenant hook.
            ItemLevel::query()->create([
                'facility_id' => $command->facilityId,
                'item_id' => $command->itemId,
                'min_level' => $command->minLevel,
            ]);

            return;
        }

        $existing->update(['min_level' => $command->minLevel]);
    }
}
