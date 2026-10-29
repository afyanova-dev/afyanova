<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain;

use App\Domains\Theatre\Domain\Model\SurgicalProcedure;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for SurgicalProcedure aggregate (ADR-156 D-C).
 */
interface SurgicalProcedureRepository extends Repository
{
    public function getById(string $id): SurgicalProcedure;

    public function getByIdForUpdate(string $id): SurgicalProcedure;

    /** The next 1-based sequence number for a case's procedures. */
    public function nextSequence(string $caseId): int;
}
