<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Repository;

use App\Domains\Laboratory\Domain\LabSpecimenRepository;
use App\Domains\Laboratory\Domain\Model\LabSpecimen;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of LabSpecimenRepository (ADR-158 D-D).
 */
final class EloquentLabSpecimenRepository extends EloquentRepository implements LabSpecimenRepository
{
    protected function model(): string
    {
        return LabSpecimen::class;
    }

    public function getById(string $id): LabSpecimen
    {
        return LabSpecimen::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(LabSpecimen::class, $id);
    }

    public function getByIdForUpdate(string $id): LabSpecimen
    {
        return LabSpecimen::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(LabSpecimen::class, $id);
    }
}
