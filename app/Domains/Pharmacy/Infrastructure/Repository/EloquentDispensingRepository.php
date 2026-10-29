<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Infrastructure\Repository;

use App\Domains\Pharmacy\Domain\DispensingRepository;
use App\Domains\Pharmacy\Domain\Model\Dispensing;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see DispensingRepository} (IC §9; ADR-184 D-B).
 */
final class EloquentDispensingRepository extends EloquentRepository implements DispensingRepository
{
    protected function model(): string
    {
        return Dispensing::class;
    }

    public function getById(string $id): Dispensing
    {
        return Dispensing::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Dispensing::class, $id);
    }

    public function getByIdForUpdate(string $id): Dispensing
    {
        return Dispensing::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Dispensing::class, $id);
    }
}
