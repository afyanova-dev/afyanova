<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Infrastructure\Repository;

use App\Domains\CareCoordination\Domain\FollowUpRepository;
use App\Domains\CareCoordination\Domain\Model\FollowUp;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of FollowUpRepository (ADR-154 D-B).
 */
final class EloquentFollowUpRepository extends EloquentRepository implements FollowUpRepository
{
    protected function model(): string
    {
        return FollowUp::class;
    }

    public function getById(string $id): FollowUp
    {
        return FollowUp::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(FollowUp::class, $id);
    }

    public function getByIdForUpdate(string $id): FollowUp
    {
        return FollowUp::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(FollowUp::class, $id);
    }
}
