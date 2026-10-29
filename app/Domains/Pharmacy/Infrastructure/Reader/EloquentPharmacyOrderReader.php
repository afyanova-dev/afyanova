<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Infrastructure\Reader;

use App\Domains\Pharmacy\Contracts\Dto\PharmacyOrderSummary;
use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Domains\Pharmacy\Domain\PharmacyOrderReader;
use App\Shared\Domain\NotFound;

final class EloquentPharmacyOrderReader implements PharmacyOrderReader
{
    public function summary(string $pharmacyOrderId): PharmacyOrderSummary
    {
        $order = PharmacyOrder::query()->whereKey($pharmacyOrderId)->first()
            ?? throw NotFound::aggregate(PharmacyOrder::class, $pharmacyOrderId);

        return $this->toSummary($order);
    }

    public function forFacility(string $facilityId, ?string $status): array
    {
        $query = PharmacyOrder::query()->where('facility_id', $facilityId);

        if ($status !== null) {
            $query->where('status', strtoupper(trim($status)));
        }

        return array_values(
            $query->orderByDesc('recorded_at')->orderBy('id')->get()
                ->map(fn (PharmacyOrder $o): PharmacyOrderSummary => $this->toSummary($o))
                ->all()
        );
    }

    private function toSummary(PharmacyOrder $o): PharmacyOrderSummary
    {
        return new PharmacyOrderSummary(
            pharmacyOrderId: (string) $o->id,
            orderNumber: (string) $o->order_number,
            facilityId: (string) $o->facility_id,
            prescriptionId: (string) $o->prescription_id,
            patientId: (string) $o->patient_id,
            dispensingLocationId: $o->dispensing_location_id !== null ? (string) $o->dispensing_location_id : null,
            priority: (string) $o->priority,
            status: (string) $o->status,
            validatedByUserId: $o->validated_by_user_id !== null ? (string) $o->validated_by_user_id : null,
            rejectReasonCode: $o->reject_reason_code,
            holdReasonCode: $o->hold_reason_code,
            cancelReasonCode: $o->cancel_reason_code,
            reasonNote: $o->reason_note,
            cancelledByUserId: $o->cancelled_by_user_id !== null ? (string) $o->cancelled_by_user_id : null,
            occurredAt: $o->occurred_at->toIso8601String(),
            recordedAt: $o->recorded_at->toIso8601String(),
        );
    }
}
