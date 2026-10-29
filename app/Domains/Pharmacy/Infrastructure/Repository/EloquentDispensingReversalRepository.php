<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Infrastructure\Repository;

use App\Domains\Pharmacy\Domain\DispensingReversalRepository;
use App\Domains\Pharmacy\Domain\Model\DispensingReversal;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see DispensingReversalRepository} (IC §9; ADR-185 D-B).
 */
final class EloquentDispensingReversalRepository extends EloquentRepository implements DispensingReversalRepository
{
    protected function model(): string
    {
        return DispensingReversal::class;
    }

    public function getById(string $id): DispensingReversal
    {
        return DispensingReversal::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(DispensingReversal::class, $id);
    }

    public function getByIdForUpdate(string $id): DispensingReversal
    {
        return DispensingReversal::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(DispensingReversal::class, $id);
    }

    public function existsForDispensing(string $dispensingId): bool
    {
        return DispensingReversal::query()->where('dispensing_id', $dispensingId)->exists();
    }
}
