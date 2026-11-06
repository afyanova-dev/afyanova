<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Repository;

use App\Domains\Laboratory\Domain\LabExecutionRepository;
use App\Domains\Laboratory\Domain\Model\LabExecution;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

final class EloquentLabExecutionRepository extends EloquentRepository implements LabExecutionRepository
{
    protected function model(): string
    {
        return LabExecution::class;
    }

    public function getById(string $id): LabExecution
    {
        return LabExecution::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(LabExecution::class, $id);
    }

    public function getByIdForUpdate(string $id): LabExecution
    {
        return LabExecution::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(LabExecution::class, $id);
    }
}
