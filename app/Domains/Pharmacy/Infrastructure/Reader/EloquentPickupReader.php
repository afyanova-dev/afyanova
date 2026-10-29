<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Infrastructure\Reader;

use App\Domains\Pharmacy\Contracts\Dto\PickupSummary;
use App\Domains\Pharmacy\Domain\Model\Pickup;
use App\Domains\Pharmacy\Domain\PickupReader;
use App\Shared\Domain\NotFound;

final class EloquentPickupReader implements PickupReader
{
    public function summary(string $pickupId): PickupSummary
    {
        $pickup = Pickup::query()->whereKey($pickupId)->first()
            ?? throw NotFound::aggregate(Pickup::class, $pickupId);

        return $this->toSummary($pickup);
    }

    public function forFacility(string $facilityId, ?string $status): array
    {
        $query = Pickup::query()->where('facility_id', $facilityId);

        if ($status !== null) {
            $query->where('status', strtoupper(trim($status)));
        }

        return array_values(
            $query->orderByDesc('recorded_at')->orderBy('id')->get()
                ->map(fn (Pickup $p): PickupSummary => $this->toSummary($p))
                ->all()
        );
    }

    private function toSummary(Pickup $p): PickupSummary
    {
        return new PickupSummary(
            pickupId: (string) $p->id,
            pickupNumber: (string) $p->pickup_number,
            facilityId: (string) $p->facility_id,
            dispensingId: (string) $p->dispensing_id,
            pharmacyOrderId: (string) $p->pharmacy_order_id,
            prescriptionId: (string) $p->prescription_id,
            patientId: (string) $p->patient_id,
            status: (string) $p->status,
            recipientType: $p->recipient_type,
            recipientName: $p->recipient_name,
            verificationNote: $p->verification_note,
            collectedByUserId: $p->collected_by_user_id !== null ? (string) $p->collected_by_user_id : null,
            collectedAt: $p->collected_at?->toIso8601String(),
            outcomeReasonCode: $p->outcome_reason_code,
            outcomeNote: $p->outcome_note,
            occurredAt: $p->occurred_at->toIso8601String(),
            recordedAt: $p->recorded_at->toIso8601String(),
        );
    }
}
