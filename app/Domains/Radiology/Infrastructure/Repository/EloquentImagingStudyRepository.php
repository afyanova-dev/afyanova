<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Infrastructure\Repository;

use App\Domains\Radiology\Domain\ImagingStudyRepository;
use App\Domains\Radiology\Domain\Model\ImagingStudy;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of ImagingStudyRepository (ADR-166 D-B).
 */
final class EloquentImagingStudyRepository extends EloquentRepository implements ImagingStudyRepository
{
    protected function model(): string
    {
        return ImagingStudy::class;
    }

    public function getById(string $id): ImagingStudy
    {
        return ImagingStudy::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(ImagingStudy::class, $id);
    }

    public function getByIdForUpdate(string $id): ImagingStudy
    {
        return ImagingStudy::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(ImagingStudy::class, $id);
    }

    public function activeForOrderItem(string $orderItemId): ?ImagingStudy
    {
        return ImagingStudy::query()
            ->where('order_item_id', $orderItemId)
            ->whereIn('status', ImagingStudy::NON_TERMINAL_STATUSES)
            ->first();
    }
}
