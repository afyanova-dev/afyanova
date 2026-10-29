<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain;

use App\Domains\Theatre\Contracts\Dto\SurgicalProcedureSummary;

/**
 * Read-side port for SurgicalProcedure queries (ADR-156 D-C).
 */
interface SurgicalProcedureReader
{
    /**
     * A case's procedures, sequence order.
     *
     * @return list<SurgicalProcedureSummary>
     */
    public function forCase(string $caseId): array;

    public function summary(string $surgicalProcedureId): SurgicalProcedureSummary;
}
