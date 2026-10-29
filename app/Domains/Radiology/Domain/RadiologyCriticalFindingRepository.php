<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain;

use App\Domains\Radiology\Domain\Model\RadiologyCriticalFinding;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for the RadiologyCriticalFinding aggregate
 * (ADR-169 D-B).
 */
interface RadiologyCriticalFindingRepository extends Repository
{
    public function getById(string $id): RadiologyCriticalFinding;

    public function getByIdForUpdate(string $id): RadiologyCriticalFinding;
}
