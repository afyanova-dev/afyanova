<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * References provided to a PrescribingDecision command are not mutually consistent
 * (ADR-143 D-H / D-K / D-M).
 * Maps to HTTP 409 (IC §15).
 */
final class PrescribingDecisionContextMismatch extends DomainException
{
    public static function patientEncounter(string $commandPatientId, string $encounterId, string $encounterPatientId): self
    {
        return new self(sprintf(
            'RecordPrescribingDecision named patient [%s] but encounter [%s] belongs to patient [%s].',
            $commandPatientId,
            $encounterId,
            $encounterPatientId,
        ));
    }

    public static function clinicalPlan(string $patientId, string $planId, string $planPatientId): self
    {
        return new self(sprintf(
            'PrescribingDecision for patient [%s] named clinicalPlanId [%s], which belongs to patient [%s].',
            $patientId,
            $planId,
            $planPatientId,
        ));
    }

    public static function indicationDiagnosis(string $patientId, string $diagnosisId, string $diagnosisPatientId): self
    {
        return new self(sprintf(
            'PrescribingDecision for patient [%s] named indicationDiagnosisId [%s], which belongs to patient [%s].',
            $patientId,
            $diagnosisId,
            $diagnosisPatientId,
        ));
    }

    public static function indicationProblem(string $patientId, string $problemId, string $problemPatientId): self
    {
        return new self(sprintf(
            'PrescribingDecision for patient [%s] named indicationProblemId [%s], which belongs to patient [%s].',
            $patientId,
            $problemId,
            $problemPatientId,
        ));
    }

    public function errorCode(): string
    {
        return 'prescribing_decision_context_mismatch';
    }
}
