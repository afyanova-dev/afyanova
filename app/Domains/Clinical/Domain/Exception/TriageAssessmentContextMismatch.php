<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RecordTriageAssessment` named references that are not mutually consistent
 * (12-clinical-domain.md §6–§8; ADR-135 / ADR-145 D-G):
 *
 *   - `patientEncounter` — the `patientId` is not the patient of the
 *     `encounterId` (`EncounterApi::getEncounterContext` is authoritative);
 *   - `sourceVitals` — the optional `sourceVitalsObservationId` resolves to a
 *     C.2 observation on a DIFFERENT patient or a DIFFERENT encounter (the
 *     ADR-140 D-I read-only provenance-check precedent).
 *
 * A command whose references disagree is rejected before any row is written.
 * Maps to HTTP 409 (IC §15). (The triage analogue of `DiagnosisContextMismatch`
 * / `ProblemContextMismatch`.)
 */
final class TriageAssessmentContextMismatch extends DomainException
{
    public static function patientEncounter(string $commandPatientId, string $encounterId, string $encounterPatientId): self
    {
        return new self(sprintf(
            'RecordTriageAssessment named patient [%s] but encounter [%s] belongs to patient [%s].',
            $commandPatientId,
            $encounterId,
            $encounterPatientId,
        ));
    }

    public static function sourceVitals(string $encounterId, string $vitalsObservationId, string $vitalsEncounterId): self
    {
        return new self(sprintf(
            'RecordTriageAssessment on encounter [%s] referenced vitals observation [%s], which belongs to encounter [%s].',
            $encounterId,
            $vitalsObservationId,
            $vitalsEncounterId,
        ));
    }

    public function errorCode(): string
    {
        return 'triage_assessment_context_mismatch';
    }
}
