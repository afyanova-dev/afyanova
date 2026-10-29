<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain;

use App\Domains\Theatre\Contracts\Dto\SurgicalCaseSummary;

/**
 * Read-side port for SurgicalCase queries (ADR-150 D-J).
 */
interface SurgicalCaseReader
{
    public function summary(string $caseId): SurgicalCaseSummary;

    /**
     * @return list<SurgicalCaseSummary>
     */
    public function activeCases(string $facilityId): array;
}
