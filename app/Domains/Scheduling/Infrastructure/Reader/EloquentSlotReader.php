<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Infrastructure\Reader;

use App\Domains\Scheduling\Contracts\Dto\SlotSummary;
use App\Domains\Scheduling\Domain\Model\Slot;
use App\Domains\Scheduling\Domain\SlotReader;
use App\Domains\Scheduling\Domain\ValueObject\SlotStatus;
use Carbon\CarbonImmutable;

final class EloquentSlotReader implements SlotReader
{
    public function findById(string $id): ?SlotSummary
    {
        $row = Slot::query()->whereKey($id)->first();

        return $row !== null ? $this->toSummary($row) : null;
    }

    public function getAvailableSlots(
        string $facilityId,
        ?string $resourceId,
        ?string $appointmentTypeId,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $now = CarbonImmutable::now();

        $query = Slot::query()
            ->where('facility_id', $facilityId)
            ->whereBetween('slot_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->where(function ($q) use ($now): void {
                $q->where('status', SlotStatus::OPEN->value)
                    ->orWhere(function ($sub) use ($now): void {
                        $sub->where('status', SlotStatus::HELD->value)
                            ->where('held_until', '<', $now);
                    });
            });

        if ($resourceId !== null) {
            $query->where('resource_id', $resourceId);
        }

        if ($appointmentTypeId !== null) {
            $query->where(function ($q) use ($appointmentTypeId): void {
                $q->where('appointment_type_id', $appointmentTypeId)
                    ->orWhereNull('appointment_type_id');
            });
        }

        return array_values($query->orderBy('start_time')
            ->get()
            ->map(fn (Slot $s): SlotSummary => $this->toSummary($s))
            ->all());
    }

    private function toSummary(Slot $s): SlotSummary
    {
        return new SlotSummary(
            id: (string) $s->id,
            facilityId: (string) $s->facility_id,
            resourceId: (string) $s->resource_id,
            appointmentTypeId: $s->appointment_type_id !== null ? (string) $s->appointment_type_id : null,
            slotDate: $s->slot_date->toDateString(),
            startTime: $s->start_time->toIso8601String(),
            endTime: $s->end_time->toIso8601String(),
            capacity: (int) $s->capacity,
            bookedCount: (int) $s->booked_count,
            status: $s->status->value,
            heldUntil: $s->held_until?->toIso8601String(),
            heldBy: $s->held_by,
        );
    }
}
