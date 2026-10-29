<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Contracts\Dto\LabCriticalResultSummary;

/**
 * Read-side port for LabCriticalResult queries (ADR-161 D-G).
 */
interface LabCriticalResultReader
{
    public function summary(string $criticalResultId): LabCriticalResultSummary;

    /**
     * Unacknowledged critical results at a facility — the critical-result
     * queue (§71, §147 GetCriticalResults).
     *
     * @return list<LabCriticalResultSummary>
     */
    public function pendingForFacility(string $facilityId): array;
}
