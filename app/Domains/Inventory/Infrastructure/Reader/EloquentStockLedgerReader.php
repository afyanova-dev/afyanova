<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Reader;

use App\Domains\Inventory\Contracts\Dto\StockLedgerEntrySummary;
use App\Domains\Inventory\Contracts\Dto\StockLedgerSummary;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Domains\Inventory\Domain\Quantity;
use App\Domains\Inventory\Domain\StockLedgerReader;
use Illuminate\Database\Eloquent\Builder;

final class EloquentStockLedgerReader implements StockLedgerReader
{
    public function history(string $itemId, string $locationId): StockLedgerSummary
    {
        $movements = StockMovement::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->orderBy('occurred_at')
            ->orderBy('movement_number')
            ->get();

        $running = '0';
        $entries = [];

        foreach ($movements as $movement) {
            $delta = $movement->direction === StockMovement::DIRECTION_IN
                ? Quantity::of((string) $movement->quantity)
                : '-'.Quantity::of((string) $movement->quantity);
            $running = Quantity::add($running, $delta);

            $entries[] = new StockLedgerEntrySummary(
                movementId: (string) $movement->id,
                movementNumber: (string) $movement->movement_number,
                movementType: (string) $movement->movement_type,
                direction: (string) $movement->direction,
                quantity: Quantity::of((string) $movement->quantity),
                uom: (string) $movement->uom,
                enteredUnit: $movement->entered_unit !== null ? (string) $movement->entered_unit : null,
                enteredQuantity: $movement->entered_quantity !== null ? Quantity::of((string) $movement->entered_quantity) : null,
                packSize: $movement->pack_size !== null ? Quantity::of((string) $movement->pack_size) : null,
                runningBalance: $running,
                sourceType: (string) $movement->source_type,
                sourceRef: $movement->source_ref !== null ? (string) $movement->source_ref : null,
                poRef: $movement->po_ref !== null ? (string) $movement->po_ref : null,
                batchId: $movement->batch_id !== null ? (string) $movement->batch_id : null,
                reasonText: $movement->reason_text,
                actorUserId: (string) $movement->actor_user_id,
                occurredAt: $movement->occurred_at->toIso8601String(),
            );
        }

        $currentOnHand = Quantity::of((string) StockBalance::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->sum('on_hand_qty'));

        return new StockLedgerSummary(
            itemId: $itemId,
            locationId: $locationId,
            currentOnHandQty: $currentOnHand,
            ledgerSum: Quantity::of($running),
            drift: Quantity::sub($currentOnHand, $running),
            entries: $entries,
        );
    }

    public function signedMovementSum(string $itemId, string $locationId, ?string $batchId): string
    {
        $sum = '0';

        StockMovement::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->when(
                $batchId === null,
                static fn (Builder $q): Builder => $q->whereNull('batch_id'),
                static fn (Builder $q): Builder => $q->where('batch_id', $batchId),
            )
            ->get(['direction', 'quantity'])
            ->each(function (StockMovement $movement) use (&$sum): void {
                $delta = $movement->direction === StockMovement::DIRECTION_IN
                    ? Quantity::of((string) $movement->quantity)
                    : '-'.Quantity::of((string) $movement->quantity);
                $sum = Quantity::add($sum, $delta);
            });

        return $sum;
    }

    public function hasMovementsForItem(string $itemId): bool
    {
        return StockMovement::query()
            ->where('item_id', $itemId)
            ->exists();
    }
}
