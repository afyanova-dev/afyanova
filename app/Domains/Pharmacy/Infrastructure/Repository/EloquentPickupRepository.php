<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Infrastructure\Repository;

use App\Domains\Pharmacy\Domain\Model\Pickup;
use App\Domains\Pharmacy\Domain\PickupRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see PickupRepository} (IC §9; ADR-186 D-B).
 */
final class EloquentPickupRepository extends EloquentRepository implements PickupRepository
{
    protected function model(): string
    {
        return Pickup::class;
    }

    public function getById(string $id): Pickup
    {
        return Pickup::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Pickup::class, $id);
    }

    public function getByIdForUpdate(string $id): Pickup
    {
        return Pickup::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Pickup::class, $id);
    }

    public function forDispensingForUpdate(string $dispensingId): Pickup
    {
        return Pickup::query()->where('dispensing_id', $dispensingId)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Pickup::class, $dispensingId);
    }

    public function forDispensing(string $dispensingId): ?Pickup
    {
        return Pickup::query()->where('dispensing_id', $dispensingId)->first();
    }
}
