<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `AddProblem` named references that are not mutually consistent
 * (12-clinical-domain.md §6–§8, §26; ADR-140 D-O / D-I). A longitudinal problem
 * is bound to exactly one patient AND one originating encounter, and — when a
 * `sourceDiagnosisId` is supplied — that diagnosis must belong to the same
 * patient.
 *
 *   - `patientEncounter`  — the command's `patientId` is not the patient of the
 *                           `encounterId` it also named (the encounter is
 *                           authoritative for the patient link — ADR-135;
 *                           the `DiagnosisContextMismatch` precedent).
 *   - `sourceDiagnosis`   — the optional `sourceDiagnosisId` resolves (same
 *                           tenant) but references a different patient
 *                           (ADR-140 D-I — provenance requires patient
 *                           consistency).
 *
 * Maps to HTTP 409 (IC §15). A `sourceDiagnosisId` that does not resolve at all
 * within the tenant surfaces as `App\Shared\Domain\NotFound` from the
 * `DiagnosisReader`, not this exception.
 */
final class ProblemContextMismatch extends DomainException
{
    public static function patientEncounter(string $commandPatientId, string $encounterId, string $encounterPatientId): self
    {
        return new self(sprintf(
            'AddProblem named patient [%s] but encounter [%s] belongs to patient [%s].',
            $commandPatientId,
            $encounterId,
            $encounterPatientId,
        ));
    }

    public static function sourceDiagnosis(string $problemPatientId, string $diagnosisId, string $diagnosisPatientId): self
    {
        return new self(sprintf(
            'AddProblem for patient [%s] named sourceDiagnosisId [%s], which belongs to patient [%s].',
            $problemPatientId,
            $diagnosisId,
            $diagnosisPatientId,
        ));
    }

    public function errorCode(): string
    {
        return 'problem_context_mismatch';
    }
}
