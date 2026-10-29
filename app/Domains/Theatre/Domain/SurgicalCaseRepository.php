<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain;

use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for SurgicalCase aggregate (ADR-150 D-C).
 */
interface SurgicalCaseRepository extends Repository
{
    public function getById(string $id): SurgicalCase;

    public function getByIdForUpdate(string $id): SurgicalCase;
}
