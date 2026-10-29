<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Repository;

use App\Domains\Laboratory\Domain\LabQcRunRepository;
use App\Domains\Laboratory\Domain\Model\LabQcRun;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of LabQcRunRepository (ADR-163 D-D).
 */
final class EloquentLabQcRunRepository extends EloquentRepository implements LabQcRunRepository
{
    protected function model(): string
    {
        return LabQcRun::class;
    }

    public function getById(string $id): LabQcRun
    {
        return LabQcRun::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(LabQcRun::class, $id);
    }

    public function getByIdForUpdate(string $id): LabQcRun
    {
        return LabQcRun::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(LabQcRun::class, $id);
    }
}
