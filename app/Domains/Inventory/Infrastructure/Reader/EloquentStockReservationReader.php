<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Reader;

use App\Domains\Inventory\Contracts\Dto\StockReservationSummary;
use App\Domains\Inventory\Domain\Model\StockReservation;
use App\Domains\Inventory\Domain\Quantity;
use App\Domains\Inventory\Domain\StockReservationReader;
use App\Shared\Domain\NotFound;

final class EloquentStockReservationReader implements StockReservationReader
{
    public function summary(string $reservationId): StockReservationSummary
    {
        $reservation = StockReservation::query()->whereKey($reservationId)->first()
            ?? throw NotFound::aggregate(StockReservation::class, $reservationId);

        return $this->toSummary($reservation);
    }

    public function search(
        string $facilityId,
        ?string $itemId = null,
        ?string $locationId = null,
        ?string $status = null,
        ?string $consumerRef = null,
    ): array {
        $query = StockReservation::query()->where('facility_id', $facilityId);

        if ($itemId !== null) {
            $query->where('item_id', $itemId);
        }
        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }
        if ($status !== null) {
            $query->where('status', strtoupper(trim($status)));
        }
        if ($consumerRef !== null) {
            $query->where('consumer_ref', trim($consumerRef));
        }

        return array_values(
            $query->orderByDesc('occurred_at')->orderBy('id')->get()
                ->map(fn (StockReservation $r): StockReservationSummary => $this->toSummary($r))
                ->all()
        );
    }

    private function toSummary(StockReservation $r): StockReservationSummary
    {
        return new StockReservationSummary(
            reservationId: (string) $r->id,
            facilityId: (string) $r->facility_id,
            itemId: (string) $r->item_id,
            locationId: (string) $r->location_id,
            batchId: $r->batch_id !== null ? (string) $r->batch_id : null,
            reservedQty: Quantity::of((string) $r->reserved_qty),
            consumerType: (string) $r->consumer_type,
            consumerRef: $r->consumer_ref,
            reason: $r->reason,
            holdUntil: $r->hold_until?->toIso8601String(),
            status: (string) $r->status,
            releasedReason: $r->released_reason,
            fulfilledByMovementId: $r->fulfilled_by_movement_id !== null ? (string) $r->fulfilled_by_movement_id : null,
            requestedByUserId: (string) $r->requested_by_user_id,
            releasedByUserId: $r->released_by_user_id !== null ? (string) $r->released_by_user_id : null,
            createdAt: $r->created_at?->toIso8601String(),
        );
    }
}
