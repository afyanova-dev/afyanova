<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain;

use App\Domains\CareCoordination\Domain\Model\CareEpisodeEncounter;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for CareEpisodeEncounter aggregate (ADR-153 D-B).
 */
interface CareEpisodeEncounterRepository extends Repository
{
    public function getById(string $id): CareEpisodeEncounter;

    public function getByIdForUpdate(string $id): CareEpisodeEncounter;
}
