<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Contracts\Dto\LabQcRunSummary;

/**
 * Read-side port for LabQcRun queries (ADR-163 D-G).
 */
interface LabQcRunReader
{
    public function summary(string $qcRunId): LabQcRunSummary;

    /**
     * Recent QC runs at a facility, newest first (the QC board — §74).
     *
     * @return list<LabQcRunSummary>
     */
    public function recentForFacility(string $facilityId, int $limit = 50): array;
}
