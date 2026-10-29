<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Infrastructure\Repository;

use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of SurgicalCaseRepository (ADR-150 D-C).
 */
final class EloquentSurgicalCaseRepository extends EloquentRepository implements SurgicalCaseRepository
{
    protected function model(): string
    {
        return SurgicalCase::class;
    }

    public function getById(string $id): SurgicalCase
    {
        return SurgicalCase::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(SurgicalCase::class, $id);
    }

    public function getByIdForUpdate(string $id): SurgicalCase
    {
        return SurgicalCase::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(SurgicalCase::class, $id);
    }
}
