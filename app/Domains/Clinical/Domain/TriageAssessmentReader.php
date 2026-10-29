<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Contracts\Dto\TriageAssessmentSummary;

/**
 * Read-side port for TriageAssessment (IC §6.2 / §6.3; ADR-145 D-H).
 * Implementation: `Infrastructure/Reader/EloquentTriageAssessmentReader`.
 * Returns DTOs only — never an Eloquent model (IC §9).
 *
 * Exactly two read capabilities (ADR-145 D-H) — one internal interface:
 *
 *   - `summary`      backs the `GetTriageAssessment` protected query (by id);
 *   - `forEncounter` backs the `GetEncounterTriageAssessments` protected query
 *                    (one encounter's triage assessments; `$currentOnly` false
 *                    by default; deterministic ordering; NO pagination;
 *                    originating-facility-scoped via `$inScopeFacilityIds`).
 *
 * C.9 publishes NO `ClinicalApi` triage method, NO `clin_rm_*` projection and NO
 * patient-level / timeline reader (ADR-145 D-B / D-H — the Queueing consumer and
 * any workspace read are PHASE 12 / PHASE 16).
 */
interface TriageAssessmentReader
{
    /**
     * Throws `App\Shared\Domain\NotFound` if the assessment does not exist in
     * the current tenant scope (RLS). No facility-scope check here — that is
     * `GetTriageAssessmentHandler`'s per-caller refinement against the
     * assessment's ORIGINATING facility (ADR-145 D-H / D-L; the E.12 R-1
     * pattern).
     */
    public function summary(string $triageAssessmentId): TriageAssessmentSummary;

    /**
     * One encounter's triage assessments, filtered to assessments whose
     * ORIGINATING `facility_id` is in `$inScopeFacilityIds` (ADR-145 D-P —
     * originating-facility-only visibility; NOT the ADR-141 D-M tenant-wide-read
     * exception). `$currentOnly` false (default) returns every assessment
     * (including superseded ones — the re-triage chain); true returns only
     * assessments with `superseded_at IS NULL` (the "current triage" read).
     * Deterministic ordering (`occurred_at`, then `id`); NO pagination (ADR-145
     * D-H). An empty `$inScopeFacilityIds` yields an empty list.
     *
     * @param  list<string>  $inScopeFacilityIds
     * @return list<TriageAssessmentSummary>
     */
    public function forEncounter(string $encounterId, array $inScopeFacilityIds, bool $currentOnly = false): array;

    /**
     * Encounter ids with a current triage assessment in the given facilities.
     *
     * @param  list<string>  $encounterIds
     * @param  list<string>  $facilityIds
     * @return list<string>
     */
    public function recordedEncounterIds(array $encounterIds, array $facilityIds): array;
}
