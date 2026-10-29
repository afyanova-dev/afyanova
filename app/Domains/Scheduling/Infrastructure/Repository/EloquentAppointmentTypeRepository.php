<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Infrastructure\Repository;

use App\Domains\Scheduling\Domain\AppointmentTypeRepository;
use App\Domains\Scheduling\Domain\Exception\AppointmentTypeNotFoundException;
use App\Domains\Scheduling\Domain\Model\AppointmentType;
use App\Shared\Domain\EloquentRepository;

final class EloquentAppointmentTypeRepository extends EloquentRepository implements AppointmentTypeRepository
{
    protected function model(): string
    {
        return AppointmentType::class;
    }

    public function getById(string $id): AppointmentType
    {
        return AppointmentType::query()->whereKey($id)->first()
            ?? throw AppointmentTypeNotFoundException::forId($id);
    }

    public function existsByCode(string $code): bool
    {
        return AppointmentType::query()->where('code', $code)->exists();
    }
}
