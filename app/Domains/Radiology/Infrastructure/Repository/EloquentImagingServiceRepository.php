<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Infrastructure\Repository;

use App\Domains\Radiology\Domain\ImagingServiceRepository;
use App\Domains\Radiology\Domain\Model\ImagingService;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see ImagingServiceRepository} (IC §9;
 * ADR-165 D-D). Emits no domain events, so `save()` is inherited unchanged.
 */
final class EloquentImagingServiceRepository extends EloquentRepository implements ImagingServiceRepository
{
    protected function model(): string
    {
        return ImagingService::class;
    }

    public function getById(string $id): ImagingService
    {
        return ImagingService::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(ImagingService::class, $id);
    }

    public function getByIdForUpdate(string $id): ImagingService
    {
        return ImagingService::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(ImagingService::class, $id);
    }

    public function findActiveByCode(string $tenantId, string $code): ?ImagingService
    {
        return ImagingService::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->where('active', true)
            ->first();
    }
}
