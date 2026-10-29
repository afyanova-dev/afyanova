<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Infrastructure\Repository;

use App\Domains\BedWard\Domain\Model\Ward;
use App\Domains\BedWard\Domain\WardRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of WardRepository (ADR-148 D-A; IC §9).
 */
final class EloquentWardRepository extends EloquentRepository implements WardRepository
{
    protected function model(): string
    {
        return Ward::class;
    }

    public function getById(string $id): Ward
    {
        return Ward::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Ward::class, $id);
    }

    public function getByIdForUpdate(string $id): Ward
    {
        return Ward::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Ward::class, $id);
    }
}
