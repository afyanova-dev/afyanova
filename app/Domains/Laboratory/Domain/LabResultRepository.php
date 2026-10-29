<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for the LabResult aggregate (ADR-159 D-D).
 */
interface LabResultRepository extends Repository
{
    public function getById(string $id): LabResult;

    public function getByIdForUpdate(string $id): LabResult;

    /** Next per-result amendment sequence (ADR-160 D-D — the T.4 precedent). */
    public function nextAmendmentSequence(string $resultId): int;
}
