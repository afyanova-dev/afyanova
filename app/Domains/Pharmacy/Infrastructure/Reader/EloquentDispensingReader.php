<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Infrastructure\Reader;

use App\Domains\Pharmacy\Contracts\Dto\DispensingSummary;
use App\Domains\Pharmacy\Domain\DispensingReader;
use App\Domains\Pharmacy\Domain\Model\Dispensing;
use App\Domains\Pharmacy\Domain\Model\DispensingReversal;
use App\Shared\Domain\NotFound;

final class EloquentDispensingReader implements DispensingReader
{
    public function summary(string $dispensingId): DispensingSummary
    {
        $dispensing = Dispensing::query()->whereKey($dispensingId)->first()
            ?? throw NotFound::aggregate(Dispensing::class, $dispensingId);

        $reversal = DispensingReversal::query()->where('dispensing_id', $dispensingId)->first();

        return $this->toSummary($dispensing, $reversal);
    }

    public function forOrder(string $pharmacyOrderId): array
    {
        $dispensings = Dispensing::query()
            ->where('pharmacy_order_id', $pharmacyOrderId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $reversals = DispensingReversal::query()
            ->where('pharmacy_order_id', $pharmacyOrderId)
            ->get()
            ->keyBy(fn (DispensingReversal $r): string => (string) $r->dispensing_id);

        return array_values(
            $dispensings
                ->map(fn (Dispensing $d): DispensingSummary => $this->toSummary(
                    $d,
                    $reversals->get((string) $d->id),
                ))
                ->all()
        );
    }

    public function cumulativeForOrder(string $pharmacyOrderId): string
    {
        $dispensed = Dispensing::query()->where('pharmacy_order_id', $pharmacyOrderId)->sum('quantity');
        $reversed = DispensingReversal::query()->where('pharmacy_order_id', $pharmacyOrderId)->sum('quantity');

        return bcsub(self::scale((string) $dispensed), self::scale((string) $reversed), 4);
    }

    /**
     * Fixed-scale decimal string — the `Procurement\Domain\Quantity::of` house
     * pattern (ADR-184 D-L: no new Pharmacy value object).
     *
     * @return numeric-string
     */
    private static function scale(string $value): string
    {
        return is_numeric($value) ? bcadd($value, '0', 4) : '0.0000';
    }

    private function toSummary(Dispensing $d, ?DispensingReversal $reversal): DispensingSummary
    {
        return new DispensingSummary(
            dispensingId: (string) $d->id,
            dispensingNumber: (string) $d->dispensing_number,
            facilityId: (string) $d->facility_id,
            pharmacyOrderId: (string) $d->pharmacy_order_id,
            prescriptionId: (string) $d->prescription_id,
            patientId: (string) $d->patient_id,
            itemId: (string) $d->item_id,
            locationId: (string) $d->location_id,
            batchNumber: $d->batch_number,
            quantity: (string) $d->quantity,
            quantityUnit: $d->quantity_unit,
            authorizedQuantityValue: $d->authorized_quantity_value !== null ? (string) $d->authorized_quantity_value : null,
            authorizedQuantityUnit: $d->authorized_quantity_unit,
            dispensedWithoutCeiling: (bool) $d->dispensed_without_ceiling,
            reservationId: (string) $d->reservation_id,
            movementId: (string) $d->movement_id,
            financialClearanceRef: $d->financial_clearance_ref,
            dispensedByUserId: (string) $d->dispensed_by_user_id,
            status: (string) $d->status,
            occurredAt: $d->occurred_at->toIso8601String(),
            recordedAt: $d->recorded_at->toIso8601String(),
            reversalId: $reversal !== null ? (string) $reversal->id : null,
            reversalNumber: $reversal?->reversal_number,
            reversalReasonCode: $reversal?->reason_code,
            reversedByUserId: $reversal !== null ? (string) $reversal->reversed_by_user_id : null,
            reversedAt: $reversal?->occurred_at?->toIso8601String(),
        );
    }
}
