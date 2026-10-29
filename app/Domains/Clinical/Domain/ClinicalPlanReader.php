<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Contracts\Dto\ClinicalPlanSummary;

/**
 * Read-side port for ClinicalPlan (IC §6.2 / §6.3; ADR-143 D-Q).
 * Implementation: `Infrastructure/Reader/EloquentClinicalPlanReader`.
 */
interface ClinicalPlanReader
{
    /**
     * Throws `App\Shared\Domain\NotFound` if the plan does not exist in current tenant scope.
     */
    public function summary(string $planId): ClinicalPlanSummary;

    /**
     * One patient's clinical plans, filtered to plans whose ORIGINATING
     * facility_id is in $inScopeFacilityIds (ADR-143 D-P / D-Q).
     *
     * @param  list<string>  $inScopeFacilityIds
     * @return list<ClinicalPlanSummary>
     */
    public function forPatient(string $patientId, ?string $status, array $inScopeFacilityIds): array;
}
