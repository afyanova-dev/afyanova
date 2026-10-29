<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Reader;

use App\Domains\Inventory\Contracts\Dto\StockAdjustmentSummary;
use App\Domains\Inventory\Domain\Model\StockAdjustment;
use App\Domains\Inventory\Domain\Quantity;
use App\Domains\Inventory\Domain\StockAdjustmentReader;
use App\Shared\Domain\NotFound;

final class EloquentStockAdjustmentReader implements StockAdjustmentReader
{
    public function summary(string $adjustmentId): StockAdjustmentSummary
    {
        $adjustment = StockAdjustment::query()->whereKey($adjustmentId)->first()
            ?? throw NotFound::aggregate(StockAdjustment::class, $adjustmentId);

        return $this->toSummary($adjustment);
    }

    public function listForFacility(string $facilityId, ?string $status = null): array
    {
        $query = StockAdjustment::query()->where('facility_id', $facilityId);

        if ($status !== null) {
            $query->where('status', strtoupper(trim($status)));
        }

        return array_values(
            $query->orderByDesc('requested_at')->orderBy('id')->get()
                ->map(fn (StockAdjustment $a): StockAdjustmentSummary => $this->toSummary($a))
                ->all()
        );
    }

    private function toSummary(StockAdjustment $adjustment): StockAdjustmentSummary
    {
        return new StockAdjustmentSummary(
            adjustmentId: (string) $adjustment->id,
            facilityId: (string) $adjustment->facility_id,
            itemId: (string) $adjustment->item_id,
            locationId: (string) $adjustment->location_id,
            batchId: $adjustment->batch_id !== null ? (string) $adjustment->batch_id : null,
            direction: (string) $adjustment->direction,
            quantity: Quantity::of((string) $adjustment->quantity),
            reasonCode: (string) $adjustment->reason_code,
            reasonText: $adjustment->reason_text,
            witnessName: $adjustment->witness_name !== null ? (string) $adjustment->witness_name : null,
            status: (string) $adjustment->status,
            requestedByUserId: (string) $adjustment->requested_by_user_id,
            requestedAt: $adjustment->requested_at->toIso8601String(),
            approvedByUserId: $adjustment->approved_by_user_id !== null ? (string) $adjustment->approved_by_user_id : null,
            approvedAt: $adjustment->approved_at?->toIso8601String(),
            rejectionReason: $adjustment->rejection_reason,
            resultingMovementId: $adjustment->resulting_movement_id !== null ? (string) $adjustment->resulting_movement_id : null,
        );
    }
}
