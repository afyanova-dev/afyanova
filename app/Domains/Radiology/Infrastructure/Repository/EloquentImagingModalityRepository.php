<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Infrastructure\Repository;

use App\Domains\Radiology\Domain\ImagingModalityRepository;
use App\Domains\Radiology\Domain\Model\ImagingModality;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see ImagingModalityRepository} (IC §9;
 * ADR-165 D-C). `ImagingModality` emits no domain events (a catalog entry —
 * the CP.1 / Lab.1 precedent), so `save()` is inherited unchanged.
 */
final class EloquentImagingModalityRepository extends EloquentRepository implements ImagingModalityRepository
{
    protected function model(): string
    {
        return ImagingModality::class;
    }

    public function getById(string $id): ImagingModality
    {
        return ImagingModality::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(ImagingModality::class, $id);
    }

    public function getByIdForUpdate(string $id): ImagingModality
    {
        return ImagingModality::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(ImagingModality::class, $id);
    }

    public function findActiveByCode(string $tenantId, string $code): ?ImagingModality
    {
        return ImagingModality::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->where('active', true)
            ->first();
    }
}
