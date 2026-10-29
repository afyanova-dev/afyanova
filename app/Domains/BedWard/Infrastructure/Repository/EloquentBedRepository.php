<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Infrastructure\Repository;

use App\Domains\BedWard\Domain\BedRepository;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of BedRepository (ADR-148 D-A; IC §9).
 */
final class EloquentBedRepository extends EloquentRepository implements BedRepository
{
    protected function model(): string
    {
        return Bed::class;
    }

    public function getById(string $id): Bed
    {
        return Bed::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Bed::class, $id);
    }

    public function getByIdForUpdate(string $id): Bed
    {
        return Bed::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Bed::class, $id);
    }
}
