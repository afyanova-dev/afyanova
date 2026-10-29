<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one longitudinal problem
 * (12-clinical-domain.md §164; ADR-140 D-L / D-R). Returned by the `GetProblem`
 * query and, as a `list<ProblemSummary>`, by `GetPatientProblems` — both via
 * `ProblemReader`.
 *
 * NOT an event payload (events carry no code and no description — ADR-136).
 * NOT `#[TypeScript]` — there is no PHASE 6 frontend consumer (ADR-140 D-R);
 * `resources/js/shared/types/generated.ts` is unchanged.
 *
 * The problem `code` and `description` ARE returned to an authorized reader:
 * the `#[ProtectedQuery]` gate on `GetProblem` plus the ADR-140 per-problem
 * originating-facility refinement IS the authorization (the E.12 /
 * `PatientSummary::$dateOfBirth` / `DiagnosisSummary` precedent).
 *
 * Identifiers + the problem's own stored fields ONLY — no resolved patient name
 * / demographics, no encounter detail, no history rows, no diagnosis content.
 * `occurredAt` / `recordedAt` / `resolvedAt` are ISO-8601 strings; `onsetDate`
 * is a `Y-m-d` date string. `status` is a `ProblemStatus` value
 * (`ACTIVE` | `RESOLVED`). `sourceDiagnosisId` is provenance only (ADR-140 D-I).
 */
final readonly class ProblemSummary
{
    public function __construct(
        public string $problemId,
        public string $patientId,
        public string $encounterId,
        public string $facilityId,
        public string $authorUserId,
        public ?string $authoredContext,
        public ?string $code,
        public ?string $codeSystem,
        public string $description,
        public string $status,
        public ?string $onsetDate,
        public ?string $resolvedAt,
        public ?string $sourceDiagnosisId,
        public string $occurredAt,
        public string $recordedAt,
    ) {}
}
