<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Infrastructure\Repository;

use App\Domains\CareCoordination\Domain\CareEpisodeRepository;
use App\Domains\CareCoordination\Domain\Model\CareEpisode;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of CareEpisodeRepository (ADR-153 D-B).
 */
final class EloquentCareEpisodeRepository extends EloquentRepository implements CareEpisodeRepository
{
    protected function model(): string
    {
        return CareEpisode::class;
    }

    public function getById(string $id): CareEpisode
    {
        return CareEpisode::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(CareEpisode::class, $id);
    }

    public function getByIdForUpdate(string $id): CareEpisode
    {
        return CareEpisode::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(CareEpisode::class, $id);
    }
}
