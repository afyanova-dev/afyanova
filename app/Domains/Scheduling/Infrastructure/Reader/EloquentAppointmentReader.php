<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Infrastructure\Reader;

use App\Domains\Scheduling\Contracts\Dto\AppointmentDetail;
use App\Domains\Scheduling\Contracts\Dto\AppointmentSummary;
use App\Domains\Scheduling\Domain\AppointmentReader;
use App\Domains\Scheduling\Domain\Model\Appointment;
use Carbon\CarbonImmutable;

final class EloquentAppointmentReader implements AppointmentReader
{
    public function getById(string $id): ?AppointmentDetail
    {
        $row = Appointment::query()->whereKey($id)->first();

        return $row !== null ? $this->toDetail($row) : null;
    }

    public function getByNumber(string $appointmentNumber): ?AppointmentDetail
    {
        $row = Appointment::query()->where('appointment_number', $appointmentNumber)->first();

        return $row !== null ? $this->toDetail($row) : null;
    }

    public function getPatientAppointments(string $patientId, ?string $facilityId = null): array
    {
        $query = Appointment::query()->where('patient_id', $patientId);

        if ($facilityId !== null) {
            $query->where('facility_id', $facilityId);
        }

        return array_values($query->orderByDesc('scheduled_start')
            ->get()
            ->map(fn (Appointment $a): AppointmentSummary => $this->toSummary($a))
            ->all());
    }

    public function getResourceAppointments(string $resourceId, ?string $date = null): array
    {
        $query = Appointment::query()->where('resource_id', $resourceId);

        if ($date !== null) {
            $query->whereDate('scheduled_start', $date);
        }

        return array_values($query->orderBy('scheduled_start')
            ->get()
            ->map(fn (Appointment $a): AppointmentSummary => $this->toSummary($a))
            ->all());
    }

    /**
     * @return list<AppointmentSummary>
     */
    public function getFacilityAppointments(string $facilityId, CarbonImmutable $date): array
    {
        $start = $date->startOfDay();
        $end = $date->endOfDay();

        $query = Appointment::query()
            ->where('facility_id', $facilityId)
            ->whereBetween('scheduled_start', [$start, $end]);

        return array_values($query->orderBy('scheduled_start')
            ->get()
            ->map(fn (Appointment $a): AppointmentSummary => $this->toSummary($a))
            ->all());
    }

    private function toSummary(Appointment $a): AppointmentSummary
    {
        return new AppointmentSummary(
            id: $a->id,
            facilityId: $a->facility_id,
            appointmentNumber: $a->appointment_number,
            slotId: $a->slot_id,
            patientId: $a->patient_id,
            resourceId: $a->resource_id,
            appointmentTypeId: $a->appointment_type_id,
            scheduledStart: $a->scheduled_start->toIso8601String(),
            scheduledEnd: $a->scheduled_end->toIso8601String(),
            status: $a->status->value,
            reason: $a->reason,
        );
    }

    private function toDetail(Appointment $a): AppointmentDetail
    {
        return new AppointmentDetail(
            id: $a->id,
            tenantId: $a->tenant_id,
            facilityId: $a->facility_id,
            appointmentNumber: $a->appointment_number,
            slotId: $a->slot_id,
            patientId: $a->patient_id,
            resourceId: $a->resource_id,
            appointmentTypeId: $a->appointment_type_id,
            scheduledStart: $a->scheduled_start->toIso8601String(),
            scheduledEnd: $a->scheduled_end->toIso8601String(),
            status: $a->status->value,
            reason: $a->reason,
            cancellationReason: $a->cancellation_reason,
            cancelledBy: $a->cancelled_by,
            cancelledAt: $a->cancelled_at?->toIso8601String(),
            arrivedAt: $a->arrived_at?->toIso8601String(),
            startedAt: $a->started_at?->toIso8601String(),
            completedAt: $a->completed_at?->toIso8601String(),
            version: $a->version,
            createdAt: $a->created_at->toIso8601String(),
            updatedAt: $a->updated_at->toIso8601String(),
        );
    }
}
