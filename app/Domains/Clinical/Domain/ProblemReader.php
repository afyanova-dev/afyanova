<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Contracts\Dto\ProblemSummary;

/**
 * Read-side port for Problem (IC §6.2 / §6.3; ADR-140 D-L). Implementation:
 * `Infrastructure/Reader/EloquentProblemReader`. Returns DTOs only — never an
 * Eloquent model (IC §9).
 *
 * Exactly two read capabilities (ADR-140 D-L; one interface — ADR-140
 * clarification lock, Decision 2):
 *
 *   - `summary`     backs the `GetProblem` protected query (by id);
 *   - `forPatient`  backs the `GetPatientProblems` protected query (one
 *                   patient's problem list; optional status filter;
 *                   deterministic ordering; NO pagination — Decision 3;
 *                   originating-facility-scoped).
 *
 * C.4 publishes no `ClinicalApi` problem method, no `clin_rm_*` projection and
 * no history / timeline / `ActiveProblemsView` reader (all PHASE 16 / a later
 * decision).
 */
interface ProblemReader
{
    /**
     * Throws `App\Shared\Domain\NotFound` if the problem does not exist in the
     * current tenant scope (RLS). No facility-scope check here — that is
     * `GetProblemHandler`'s per-caller refinement against the problem's
     * ORIGINATING facility (ADR-140 D-K / D-L; the E.12 R-1 pattern).
     */
    public function summary(string $problemId): ProblemSummary;

    /**
     * One patient's problem list, filtered to problems whose ORIGINATING
     * `facility_id` is in `$inScopeFacilityIds` (ADR-140 D-B / D-L —
     * originating-facility-only visibility). An optional `$status`
     * (`ProblemStatus` value) narrows to `ACTIVE` or `RESOLVED`. Deterministic
     * ordering; NO pagination (ADR-140 D-L). An empty `$inScopeFacilityIds`
     * yields an empty list.
     *
     * @param  list<string>  $inScopeFacilityIds
     * @return list<ProblemSummary>
     */
    public function forPatient(string $patientId, ?string $status, array $inScopeFacilityIds): array;
}
