<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one triage assessment
 * (12-clinical-domain.md §164; ADR-145 D-H). Returned by `GetTriageAssessment`
 * and `GetEncounterTriageAssessments` via `TriageAssessmentReader`.
 *
 * NOT an event payload — the events carry identifiers + the coded
 * `triagePriority` ONLY (ADR-136 / ADR-145 D-J). NOT `#[TypeScript]` — there is
 * no PHASE 6 frontend consumer (ADR-131 — backend only; `TriagePriority` is a
 * `final class`, not an enum — ADR-145 D-Q).
 *
 * `chiefComplaint` (free text), `acuity` and `consciousness` (open coded
 * strings) ARE returned to an authorized reader: the `#[ProtectedQuery]` gate
 * on `GetTriageAssessment` plus the ADR-145 per-assessment-facility refinement
 * IS the authorization (the `DiagnosisSummary` / `PatientSummary::$dateOfBirth`
 * precedent). They never appear in an event or an audit row.
 *
 * `triagePriority` is a coded value the clinician assigned; Queueing & Patient
 * Flow (PHASE 12) will consume it to order downstream queues — Queueing never
 * recomputes it (ADR-105).
 *
 * The supersession fields describe this assessment's place in a re-triage /
 * correction chain (ADR-145 D-F): `supersedesTriageAssessmentId` is set when
 * THIS row is itself a correction; `supersededAt` / `supersededByTriageAssessmentId`
 * are set once THIS row has been superseded by a later assessment;
 * `correctionReason` is the clinical rationale recorded on a superseding row.
 * The CURRENT triage for an encounter is the most recent row with
 * `supersededAt === null`.
 *
 * Identifiers + the assessment's own stored fields ONLY — no resolved patient
 * name / demographics, no encounter detail, no vitals values (only the
 * `sourceVitalsObservationId` reference). Times are ISO-8601 strings.
 */
final readonly class TriageAssessmentSummary
{
    public function __construct(
        public string $triageAssessmentId,
        public string $patientId,
        public string $encounterId,
        public string $facilityId,
        public string $authorUserId,
        public ?string $authoredContext,
        public string $chiefComplaint,
        public string $triagePriority,
        public ?string $acuity,
        public ?string $consciousness,
        public ?string $sourceVitalsObservationId,
        public string $occurredAt,
        public string $recordedAt,
        public ?string $supersedesTriageAssessmentId,
        public ?string $supersededAt,
        public ?string $supersededByTriageAssessmentId,
        public ?string $correctionReason,
    ) {}
}
