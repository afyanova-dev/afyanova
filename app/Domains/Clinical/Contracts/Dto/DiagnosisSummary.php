<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one diagnosis (12-clinical-domain.md
 * §164; ADR-139 D12). Returned by the `GetDiagnosis` query via
 * `DiagnosisReader::summary()`.
 *
 * NOT an event payload (events carry no code and no description — ADR-136).
 * NOT `#[TypeScript]` — there is no PHASE 6 frontend consumer (ADR-131 —
 * backend only).
 *
 * The diagnosis `code` and `description` ARE returned to an authorized reader:
 * the `#[ProtectedQuery]` gate on `GetDiagnosis` plus the ADR-139 per-diagnosis-
 * facility refinement IS the authorization (the E.12 /
 * `PatientSummary::$dateOfBirth` precedent).
 *
 * Identifiers + the diagnosis's own stored fields ONLY — no resolved patient
 * name / demographics, no encounter detail, no problem link, no rank. Times are
 * ISO-8601 strings.
 *
 * The supersession fields describe this diagnosis's place in a correction chain
 * (ADR-139 D4): `supersedesDiagnosisId` is set when THIS row is itself a
 * correction; `supersededAt` / `supersededByDiagnosisId` are set once THIS row
 * has been corrected by a later diagnosis; `correctionReason` is the clinical
 * rationale recorded on a correction row.
 */
final readonly class DiagnosisSummary
{
    public function __construct(
        public string $diagnosisId,
        public string $patientId,
        public string $encounterId,
        public string $facilityId,
        public string $authorUserId,
        public ?string $authoredContext,
        public ?string $code,
        public ?string $codeSystem,
        public string $description,
        public string $clinicalStatus,
        public string $occurredAt,
        public string $recordedAt,
        public ?string $supersedesDiagnosisId,
        public ?string $supersededAt,
        public ?string $supersededByDiagnosisId,
        public ?string $correctionReason,
    ) {}
}
