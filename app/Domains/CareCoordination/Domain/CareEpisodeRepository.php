<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain;

use App\Domains\CareCoordination\Domain\Model\CareEpisode;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for CareEpisode aggregate (ADR-153 D-B).
 */
interface CareEpisodeRepository extends Repository
{
    public function getById(string $id): CareEpisode;

    public function getByIdForUpdate(string $id): CareEpisode;
}
