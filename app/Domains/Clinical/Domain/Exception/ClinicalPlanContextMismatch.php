<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * References provided to a ClinicalPlan command are not mutually consistent
 * (ADR-143 D-N / D-M).
 * Maps to HTTP 409 (IC §15).
 */
final class ClinicalPlanContextMismatch extends DomainException
{
    public static function patientEncounter(string $commandPatientId, string $encounterId, string $encounterPatientId): self
    {
        return new self(sprintf(
            'CreateClinicalPlan named patient [%s] but encounter [%s] belongs to patient [%s].',
            $commandPatientId,
            $encounterId,
            $encounterPatientId,
        ));
    }

    public static function indicationDiagnosis(string $patientId, string $diagnosisId, string $diagnosisPatientId): self
    {
        return new self(sprintf(
            'ClinicalPlan for patient [%s] named indicationDiagnosisId [%s], which belongs to patient [%s].',
            $patientId,
            $diagnosisId,
            $diagnosisPatientId,
        ));
    }

    public static function indicationProblem(string $patientId, string $problemId, string $problemPatientId): self
    {
        return new self(sprintf(
            'ClinicalPlan for patient [%s] named indicationProblemId [%s], which belongs to patient [%s].',
            $patientId,
            $problemId,
            $problemPatientId,
        ));
    }

    public function errorCode(): string
    {
        return 'clinical_plan_context_mismatch';
    }
}
