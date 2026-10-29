<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Infrastructure\Repository;

use App\Domains\Radiology\Domain\ImagingOrderRepository;
use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of ImagingOrderRepository (ADR-165 D-E).
 */
final class EloquentImagingOrderRepository extends EloquentRepository implements ImagingOrderRepository
{
    protected function model(): string
    {
        return ImagingOrder::class;
    }

    public function getById(string $id): ImagingOrder
    {
        return ImagingOrder::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(ImagingOrder::class, $id);
    }

    public function getByIdForUpdate(string $id): ImagingOrder
    {
        return ImagingOrder::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(ImagingOrder::class, $id);
    }
}
