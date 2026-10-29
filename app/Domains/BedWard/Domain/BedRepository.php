<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain;

use App\Domains\BedWard\Domain\Model\Bed;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for Bed aggregate (ADR-148 D-A).
 */
interface BedRepository extends Repository
{
    public function getById(string $id): Bed;

    public function getByIdForUpdate(string $id): Bed;
}
