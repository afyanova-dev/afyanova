<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain;

use App\Domains\CareCoordination\Domain\Model\FollowUp;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for FollowUp aggregate (ADR-154 D-B).
 */
interface FollowUpRepository extends Repository
{
    public function getById(string $id): FollowUp;

    public function getByIdForUpdate(string $id): FollowUp;
}
