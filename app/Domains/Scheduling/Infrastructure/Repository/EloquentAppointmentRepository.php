<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Infrastructure\Repository;

use App\Domains\Scheduling\Domain\AppointmentRepository;
use App\Domains\Scheduling\Domain\Exception\AppointmentNotFoundException;
use App\Domains\Scheduling\Domain\Model\Appointment;
use App\Shared\Domain\EloquentRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class EloquentAppointmentRepository extends EloquentRepository implements AppointmentRepository
{
    protected function model(): string
    {
        return Appointment::class;
    }

    public function getById(string $id): Appointment
    {
        return Appointment::query()->whereKey($id)->first()
            ?? throw AppointmentNotFoundException::forId($id);
    }

    public function getByIdForUpdate(string $id): Appointment
    {
        return Appointment::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw AppointmentNotFoundException::forId($id);
    }

    public function existsByNumber(string $appointmentNumber): bool
    {
        return Appointment::query()->where('appointment_number', $appointmentNumber)->exists();
    }

    public function existsOverlapping(string $resourceId, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $this->overlappingQuery($resourceId, $start, $end)->exists();
    }

    public function existsOverlappingExcluding(
        string $resourceId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $excludeAppointmentId,
    ): bool {
        return $this->overlappingQuery($resourceId, $start, $end)
            ->where('id', '!=', $excludeAppointmentId)
            ->exists();
    }

    /**
     * @return Builder<Appointment>
     */
    private function overlappingQuery(string $resourceId, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return Appointment::query()
            ->where('resource_id', $resourceId)
            ->whereNotIn('status', ['CANCELLED', 'RESCHEDULED', 'NO_SHOW'])
            ->where('scheduled_start', '<', $end)
            ->where('scheduled_end', '>', $start);
    }
}
