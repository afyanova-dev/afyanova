<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Infrastructure\Repository;

use App\Domains\BedWard\Domain\BedOccupancyRepository;
use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of BedOccupancyRepository (ADR-148 D-A; IC §9).
 */
final class EloquentBedOccupancyRepository extends EloquentRepository implements BedOccupancyRepository
{
    protected function model(): string
    {
        return BedOccupancy::class;
    }

    public function getById(string $id): BedOccupancy
    {
        return BedOccupancy::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(BedOccupancy::class, $id);
    }

    public function getByIdForUpdate(string $id): BedOccupancy
    {
        return BedOccupancy::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(BedOccupancy::class, $id);
    }

    public function getActiveForBed(string $bedId): ?BedOccupancy
    {
        return BedOccupancy::query()
            ->where('bed_id', $bedId)
            ->where('status', BedOccupancy::STATUS_ACTIVE)
            ->first();
    }

    public function getActiveForEncounter(string $encounterId): ?BedOccupancy
    {
        return BedOccupancy::query()
            ->where('encounter_id', $encounterId)
            ->where('status', BedOccupancy::STATUS_ACTIVE)
            ->first();
    }
}
