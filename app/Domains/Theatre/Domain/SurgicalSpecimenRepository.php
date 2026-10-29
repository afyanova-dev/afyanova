<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain;

use App\Domains\Theatre\Domain\Model\SurgicalSpecimen;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for the SurgicalSpecimen aggregate (ADR-164 D-A).
 */
interface SurgicalSpecimenRepository extends Repository
{
    public function getById(string $id): SurgicalSpecimen;

    public function getByIdForUpdate(string $id): SurgicalSpecimen;

    /** The next 1-based per-case specimen sequence (the SurgicalProcedure precedent). */
    public function nextSequence(string $caseId): int;
}
