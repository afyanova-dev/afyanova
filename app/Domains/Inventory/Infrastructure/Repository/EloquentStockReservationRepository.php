<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Repository;

use App\Domains\Inventory\Domain\Model\StockReservation;
use App\Domains\Inventory\Domain\StockReservationRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent implementation of {@see StockReservationRepository} (IC §9;
 * ADR-180 D-C).
 */
final class EloquentStockReservationRepository extends EloquentRepository implements StockReservationRepository
{
    protected function model(): string
    {
        return StockReservation::class;
    }

    public function getById(string $id): StockReservation
    {
        return StockReservation::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(StockReservation::class, $id);
    }

    public function getByIdForUpdate(string $id): StockReservation
    {
        return StockReservation::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(StockReservation::class, $id);
    }

    public function lapsedForTuple(string $itemId, string $locationId, ?string $batchId): array
    {
        $rows = StockReservation::query()
            ->where('status', StockReservation::STATUS_ACTIVE)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->when(
                $batchId === null,
                static fn (Builder $q): Builder => $q->whereNull('batch_id'),
                static fn (Builder $q): Builder => $q->where('batch_id', $batchId),
            )
            ->whereNotNull('hold_until')
            ->where('hold_until', '<', now())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->all();

        return array_values($rows);
    }
}
