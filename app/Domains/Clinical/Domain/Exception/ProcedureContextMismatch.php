<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RequestProcedure` (or a later command) named references that are not
 * mutually consistent (13-clinical-procedures-domain.md §6–§8, §41–§42, §49;
 * ADR-135 / ADR-146 D-G / D-K):
 *
 *   - `patientEncounter`   — the `patientId` is not the patient of the
 *     `encounterId` (`EncounterApi::getEncounterContext` is authoritative);
 *   - `sourceDiagnosis`    — the optional `sourceDiagnosisId` resolves to a C.3
 *     diagnosis on a DIFFERENT encounter (the ADR-140 D-I read-only
 *     provenance-check precedent);
 *   - `sourceClinicalPlan` — the optional `sourceClinicalPlanId` resolves to a
 *     C.7 plan in a DIFFERENT tenant (a `ClinicalPlan` is longitudinal, so no
 *     same-encounter requirement — ADR-146 D-K / M7).
 *
 * A command whose references disagree is rejected before any row is written.
 * Maps to HTTP 409 (IC §15). (The procedure analogue of
 * `TriageAssessmentContextMismatch` / `ProblemContextMismatch`.)
 */
final class ProcedureContextMismatch extends DomainException
{
    public static function patientEncounter(string $commandPatientId, string $encounterId, string $encounterPatientId): self
    {
        return new self(sprintf(
            'RequestProcedure named patient [%s] but encounter [%s] belongs to patient [%s].',
            $commandPatientId,
            $encounterId,
            $encounterPatientId,
        ));
    }

    public static function sourceDiagnosis(string $encounterId, string $diagnosisId, string $diagnosisEncounterId): self
    {
        return new self(sprintf(
            'RequestProcedure on encounter [%s] referenced diagnosis [%s], which belongs to encounter [%s].',
            $encounterId,
            $diagnosisId,
            $diagnosisEncounterId,
        ));
    }

    public static function sourceClinicalPlan(string $planId): self
    {
        return new self(sprintf(
            'RequestProcedure referenced clinical plan [%s], which does not exist in this tenant.',
            $planId,
        ));
    }

    public function errorCode(): string
    {
        return 'procedure_context_mismatch';
    }
}
