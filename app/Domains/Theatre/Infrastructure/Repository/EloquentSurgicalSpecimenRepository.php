<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Infrastructure\Repository;

use App\Domains\Theatre\Domain\Model\SurgicalSpecimen;
use App\Domains\Theatre\Domain\SurgicalSpecimenRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of SurgicalSpecimenRepository (ADR-164 D-A).
 */
final class EloquentSurgicalSpecimenRepository extends EloquentRepository implements SurgicalSpecimenRepository
{
    protected function model(): string
    {
        return SurgicalSpecimen::class;
    }

    public function getById(string $id): SurgicalSpecimen
    {
        return SurgicalSpecimen::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(SurgicalSpecimen::class, $id);
    }

    public function getByIdForUpdate(string $id): SurgicalSpecimen
    {
        return SurgicalSpecimen::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(SurgicalSpecimen::class, $id);
    }

    public function nextSequence(string $caseId): int
    {
        $max = SurgicalSpecimen::query()->where('case_id', $caseId)->max('sequence');

        return ((int) $max) + 1;
    }
}
