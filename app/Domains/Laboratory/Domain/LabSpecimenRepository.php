<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Domain\Model\LabSpecimen;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for the LabSpecimen aggregate (ADR-158 D-D).
 */
interface LabSpecimenRepository extends Repository
{
    public function getById(string $id): LabSpecimen;

    public function getByIdForUpdate(string $id): LabSpecimen;
}
