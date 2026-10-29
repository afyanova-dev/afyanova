<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Repository;

use App\Domains\Laboratory\Domain\LabOrderRepository;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of LabOrderRepository (ADR-157 D-D).
 */
final class EloquentLabOrderRepository extends EloquentRepository implements LabOrderRepository
{
    protected function model(): string
    {
        return LabOrder::class;
    }

    public function getById(string $id): LabOrder
    {
        return LabOrder::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(LabOrder::class, $id);
    }

    public function getByIdForUpdate(string $id): LabOrder
    {
        return LabOrder::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(LabOrder::class, $id);
    }
}
