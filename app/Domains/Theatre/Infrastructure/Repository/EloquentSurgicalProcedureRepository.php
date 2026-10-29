<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Infrastructure\Repository;

use App\Domains\Theatre\Domain\Model\SurgicalProcedure;
use App\Domains\Theatre\Domain\SurgicalProcedureRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of SurgicalProcedureRepository (ADR-156 D-C).
 */
final class EloquentSurgicalProcedureRepository extends EloquentRepository implements SurgicalProcedureRepository
{
    protected function model(): string
    {
        return SurgicalProcedure::class;
    }

    public function getById(string $id): SurgicalProcedure
    {
        return SurgicalProcedure::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(SurgicalProcedure::class, $id);
    }

    public function getByIdForUpdate(string $id): SurgicalProcedure
    {
        return SurgicalProcedure::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(SurgicalProcedure::class, $id);
    }

    public function nextSequence(string $caseId): int
    {
        $max = SurgicalProcedure::query()->where('case_id', $caseId)->max('sequence');

        return ((int) $max) + 1;
    }
}
