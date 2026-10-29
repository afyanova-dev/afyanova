<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Domain\Model\LabQcRun;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for the LabQcRun aggregate (ADR-163 D-D).
 */
interface LabQcRunRepository extends Repository
{
    public function getById(string $id): LabQcRun;

    public function getByIdForUpdate(string $id): LabQcRun;
}
