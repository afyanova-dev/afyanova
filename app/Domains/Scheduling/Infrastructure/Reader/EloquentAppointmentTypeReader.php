<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Infrastructure\Reader;

use App\Domains\Scheduling\Contracts\Dto\AppointmentTypeSummary;
use App\Domains\Scheduling\Domain\AppointmentTypeReader;
use App\Domains\Scheduling\Domain\Model\AppointmentType;

final class EloquentAppointmentTypeReader implements AppointmentTypeReader
{
    public function findById(string $id): ?AppointmentTypeSummary
    {
        $row = AppointmentType::query()->whereKey($id)->first();

        return $row !== null ? $this->toSummary($row) : null;
    }

    public function list(?string $facilityId = null): array
    {
        $query = AppointmentType::query()->where('is_active', true);

        if ($facilityId !== null) {
            $query->where(function ($q) use ($facilityId): void {
                $q->where('facility_id', $facilityId)->orWhereNull('facility_id');
            });
        }

        return array_values($query->get()->map(fn (AppointmentType $type): AppointmentTypeSummary => $this->toSummary($type))->all());
    }

    private function toSummary(AppointmentType $type): AppointmentTypeSummary
    {
        return new AppointmentTypeSummary(
            id: (string) $type->id,
            facilityId: $type->facility_id !== null ? (string) $type->facility_id : null,
            code: (string) $type->code,
            name: (string) $type->name,
            durationMinutes: (int) $type->duration_minutes,
            bufferBeforeMinutes: (int) $type->buffer_before_minutes,
            bufferAfterMinutes: (int) $type->buffer_after_minutes,
            isVirtual: (bool) $type->is_virtual,
            allowPatientBooking: (bool) $type->allow_patient_booking,
            isActive: (bool) $type->is_active,
        );
    }
}
