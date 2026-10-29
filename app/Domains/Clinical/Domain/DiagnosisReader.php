<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Contracts\Dto\DiagnosisSummary;

/**
 * Read-side port for Diagnosis (IC §6.2 / §6.3). Implementation:
 * `Infrastructure/Reader/EloquentDiagnosisReader`. Returns a DTO only — never
 * an Eloquent model (IC §9).
 *
 * Only `summary` (backing the `GetDiagnosis` protected query, ADR-139 D12) —
 * C.3 publishes no `ClinicalApi` method, so there is no reference-style
 * existence read.
 */
interface DiagnosisReader
{
    /**
     * Throws `App\Shared\Domain\NotFound` if the diagnosis does not exist in the
     * current tenant scope (RLS). No facility-scope check here — that is
     * `GetDiagnosisHandler`'s per-caller refinement (ADR-139 D12 / the E.12 R-1
     * pattern).
     */
    public function summary(string $diagnosisId): DiagnosisSummary;
}
