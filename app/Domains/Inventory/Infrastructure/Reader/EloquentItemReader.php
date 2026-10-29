<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Reader;

use App\Domains\Inventory\Contracts\Dto\ItemSummary;
use App\Domains\Inventory\Domain\ItemReader;
use App\Domains\Inventory\Domain\Model\Item;
use App\Shared\Domain\NotFound;

final class EloquentItemReader implements ItemReader
{
    public function summary(string $itemId): ItemSummary
    {
        $item = Item::query()->whereKey($itemId)->first()
            ?? throw NotFound::aggregate(Item::class, $itemId);

        return $this->toSummary($item);
    }

    public function list(?string $itemType = null, ?bool $active = null): array
    {
        $query = Item::query();

        if ($itemType !== null) {
            $query->where('item_type', strtoupper(trim($itemType)));
        }

        if ($active !== null) {
            $query->where('active', $active);
        }

        return array_values(
            $query->orderBy('name')->orderBy('id')->get()
                ->map(fn (Item $item): ItemSummary => $this->toSummary($item))
                ->all()
        );
    }

    private function toSummary(Item $item): ItemSummary
    {
        return new ItemSummary(
            itemId: (string) $item->id,
            code: (string) $item->code,
            name: (string) $item->name,
            description: $item->description,
            category: $item->category,
            itemType: (string) $item->item_type,
            uom: (string) $item->uom,
            packUnit: $item->pack_unit !== null ? (string) $item->pack_unit : null,
            packSize: $item->pack_size !== null ? (string) $item->pack_size : null,
            trackBatch: (bool) $item->track_batch,
            trackSerial: (bool) $item->track_serial,
            trackExpiry: (bool) $item->track_expiry,
            isControlled: (bool) $item->is_controlled,
            active: (bool) $item->active,
            version: (int) $item->version,
        );
    }
}
