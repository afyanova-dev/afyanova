<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Infrastructure\Reader;

use App\Domains\Scheduling\Contracts\Dto\WaitlistEntrySummary;
use App\Domains\Scheduling\Domain\Model\WaitlistEntry;
use App\Domains\Scheduling\Domain\WaitlistEntryReader;

final class EloquentWaitlistEntryReader implements WaitlistEntryReader
{
    public function getById(string $id): ?WaitlistEntrySummary
    {
        $row = WaitlistEntry::query()->whereKey($id)->first();

        return $row !== null ? $this->toSummary($row) : null;
    }

    /**
     * @return list<WaitlistEntrySummary>
     */
    public function getFacilityWaitlist(
        string $facilityId,
        ?string $status = null,
        ?string $appointmentTypeId = null,
        ?string $resourceId = null
    ): array {
        $query = WaitlistEntry::query()->where('facility_id', $facilityId);

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($appointmentTypeId !== null) {
            $query->where('appointment_type_id', $appointmentTypeId);
        }

        if ($resourceId !== null) {
            $query->where('resource_id', $resourceId);
        }

        // Canonical priority ordering (Doc 22 §115–§117): EMERGENCY (1), URGENT (2), ROUTINE (3), then FIFO created_at
        $query->orderByRaw(
            "CASE priority WHEN 'EMERGENCY' THEN 1 WHEN 'URGENT' THEN 2 WHEN 'ROUTINE' THEN 3 ELSE 4 END ASC"
        )->orderBy('created_at');

        return array_values($query->get()
            ->map(fn (WaitlistEntry $w): WaitlistEntrySummary => $this->toSummary($w))
            ->all());
    }

    /**
     * @return list<WaitlistEntrySummary>
     */
    public function getPatientWaitlist(string $patientId, ?string $facilityId = null): array
    {
        $query = WaitlistEntry::query()->where('patient_id', $patientId);

        if ($facilityId !== null) {
            $query->where('facility_id', $facilityId);
        }

        return array_values($query->orderByDesc('created_at')
            ->get()
            ->map(fn (WaitlistEntry $w): WaitlistEntrySummary => $this->toSummary($w))
            ->all());
    }

    private function toSummary(WaitlistEntry $w): WaitlistEntrySummary
    {
        return new WaitlistEntrySummary(
            id: (string) $w->id,
            tenantId: (string) $w->tenant_id,
            facilityId: (string) $w->facility_id,
            waitlistNumber: (string) $w->waitlist_number,
            patientId: (string) $w->patient_id,
            appointmentTypeId: (string) $w->appointment_type_id,
            resourceId: $w->resource_id !== null ? (string) $w->resource_id : null,
            priority: $w->priority->value,
            status: $w->status->value,
            preferredDateStart: $w->preferred_date_start?->format('Y-m-d'),
            preferredDateEnd: $w->preferred_date_end?->format('Y-m-d'),
            notes: $w->notes,
            offeredSlotId: $w->offered_slot_id !== null ? (string) $w->offered_slot_id : null,
            offeredAt: $w->offered_at?->toIso8601String(),
            offeredUntil: $w->offered_until?->toIso8601String(),
            bookedAppointmentId: $w->booked_appointment_id !== null ? (string) $w->booked_appointment_id : null,
            createdAt: $w->created_at->toIso8601String(),
        );
    }
}
