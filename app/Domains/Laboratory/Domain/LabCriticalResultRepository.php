<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Domain\Model\LabCriticalResult;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for the LabCriticalResult aggregate (ADR-161 D-D).
 */
interface LabCriticalResultRepository extends Repository
{
    public function getById(string $id): LabCriticalResult;

    public function getByIdForUpdate(string $id): LabCriticalResult;
}
