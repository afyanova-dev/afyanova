<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Infrastructure\Repository;

use App\Domains\BedWard\Domain\BedReservationRepository;
use App\Domains\BedWard\Domain\Model\BedReservation;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of BedReservationRepository (ADR-149 D-A; IC §9).
 */
final class EloquentBedReservationRepository extends EloquentRepository implements BedReservationRepository
{
    protected function model(): string
    {
        return BedReservation::class;
    }

    public function getById(string $id): BedReservation
    {
        return BedReservation::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(BedReservation::class, $id);
    }

    public function getByIdForUpdate(string $id): BedReservation
    {
        return BedReservation::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(BedReservation::class, $id);
    }

    public function getOpenForBed(string $bedId): ?BedReservation
    {
        return BedReservation::query()
            ->where('bed_id', $bedId)
            ->whereIn('status', [BedReservation::STATUS_REQUESTED, BedReservation::STATUS_HELD])
            ->first();
    }
}
