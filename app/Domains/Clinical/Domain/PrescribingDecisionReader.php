<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Contracts\Dto\PrescribingDecisionSummary;

/**
 * Read-side port for PrescribingDecision (IC §6.2 / §6.3; ADR-143 D-Q).
 * Implementation: `Infrastructure/Reader/EloquentPrescribingDecisionReader`.
 */
interface PrescribingDecisionReader
{
    /**
     * Throws `App\Shared\Domain\NotFound` if the decision does not exist in current tenant scope.
     */
    public function summary(string $decisionId): PrescribingDecisionSummary;

    /**
     * One patient's prescribing decisions, filtered to decisions whose ORIGINATING
     * facility_id is in $inScopeFacilityIds (ADR-143 D-P / D-Q).
     *
     * @param  list<string>  $inScopeFacilityIds
     * @return list<PrescribingDecisionSummary>
     */
    public function forPatient(string $patientId, ?string $status, array $inScopeFacilityIds): array;
}
