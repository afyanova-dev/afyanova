<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Reader;

use App\Domains\Billing\Contracts\Dto\ItemBillingMapSummary;
use App\Domains\Billing\Domain\ItemBillingMapReader;
use App\Domains\Billing\Domain\Model\ItemBillingMap;

/**
 * Eloquent implementation of {@see ItemBillingMapReader} (IC §6.2; ADR-189 D-A).
 */
final class EloquentItemBillingMapReader implements ItemBillingMapReader
{
    public function activeForItem(string $invItemId): ?ItemBillingMapSummary
    {
        $row = ItemBillingMap::query()
            ->where('inv_item_id', $invItemId)
            ->where('active', true)
            ->first();

        return $row !== null ? $this->toSummary($row) : null;
    }

    public function search(?string $billingServiceId): array
    {
        $query = ItemBillingMap::query();

        if ($billingServiceId !== null) {
            $query->where('billing_service_id', $billingServiceId);
        }

        // `recorded_at` tie-breaks must still read newest-first: Laravel binds
        // datetimes at second precision, so two mappings in the same second
        // share a `recorded_at`. The id is a UUIDv7 (byte-ordered by time), so
        // descending id keeps "newest first" true within a second.
        return array_values(
            $query->orderByDesc('recorded_at')->orderByDesc('id')->get()
                ->map(fn (ItemBillingMap $m): ItemBillingMapSummary => $this->toSummary($m))
                ->all()
        );
    }

    private function toSummary(ItemBillingMap $m): ItemBillingMapSummary
    {
        return new ItemBillingMapSummary(
            mapId: (string) $m->id,
            invItemId: (string) $m->inv_item_id,
            billingServiceId: (string) $m->billing_service_id,
            active: (bool) $m->active,
            occurredAt: $m->occurred_at->toIso8601String(),
            recordedAt: $m->recorded_at->toIso8601String(),
        );
    }
}
