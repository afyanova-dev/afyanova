<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Repository;

use App\Domains\Laboratory\Domain\LabResultRepository;
use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Domains\Laboratory\Domain\Model\LabResultAmendment;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of LabResultRepository (ADR-159 D-D).
 */
final class EloquentLabResultRepository extends EloquentRepository implements LabResultRepository
{
    protected function model(): string
    {
        return LabResult::class;
    }

    public function getById(string $id): LabResult
    {
        return LabResult::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(LabResult::class, $id);
    }

    public function getByIdForUpdate(string $id): LabResult
    {
        return LabResult::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(LabResult::class, $id);
    }

    public function nextAmendmentSequence(string $resultId): int
    {
        $max = LabResultAmendment::query()->where('result_id', $resultId)->max('sequence');

        return ((int) $max) + 1;
    }
}
