<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Infrastructure\Repository;

use App\Domains\CareCoordination\Domain\CareEpisodeEncounterRepository;
use App\Domains\CareCoordination\Domain\Model\CareEpisodeEncounter;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of CareEpisodeEncounterRepository (ADR-153 D-B).
 */
final class EloquentCareEpisodeEncounterRepository extends EloquentRepository implements CareEpisodeEncounterRepository
{
    protected function model(): string
    {
        return CareEpisodeEncounter::class;
    }

    public function getById(string $id): CareEpisodeEncounter
    {
        return CareEpisodeEncounter::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(CareEpisodeEncounter::class, $id);
    }

    public function getByIdForUpdate(string $id): CareEpisodeEncounter
    {
        return CareEpisodeEncounter::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(CareEpisodeEncounter::class, $id);
    }
}
