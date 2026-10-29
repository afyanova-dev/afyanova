<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for the LabOrder aggregate (ADR-157 D-D).
 */
interface LabOrderRepository extends Repository
{
    public function getById(string $id): LabOrder;

    public function getByIdForUpdate(string $id): LabOrder;
}
