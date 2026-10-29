<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain;

use App\Domains\Theatre\Contracts\Dto\SurgicalSpecimenSummary;

/**
 * Read-side port for SurgicalSpecimen queries (ADR-164 D-D).
 */
interface SurgicalSpecimenReader
{
    public function summary(string $surgicalSpecimenId): SurgicalSpecimenSummary;

    /**
     * A case's specimens, sequence order (§131 "Specimen Sent / Lab Status").
     *
     * @return list<SurgicalSpecimenSummary>
     */
    public function forCase(string $caseId): array;
}
