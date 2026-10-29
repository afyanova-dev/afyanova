<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateClinicalNote` was attempted for a patient whose lifecycle status is
 * not `ACTIVE` (12-clinical-domain.md §6–§8; ADR-135 — the patient-state gate,
 * the `CreateEncounter` / ADR-130 E analogue). A clinical record is created
 * only against an `ACTIVE` identity:
 *
 *   - `MERGED`   — the identity is consolidated; document against the surviving
 *                  record (ADR-128);
 *   - `DECEASED` — no new clinical record is originated for a deceased patient
 *                  (identity-level death; the encounter's own outcome is
 *                  separate);
 *   - `INACTIVE` — reactivate the patient first (ADR-116).
 *
 * A provisional `ACTIVE` patient IS eligible — that is the ADR-119 emergency
 * flow. Maps to HTTP 409 (IC §15). Clinical defines its own exception rather
 * than importing Encounter's `PatientNotEligibleForEncounter` (cross-module,
 * only `Contracts` may be imported).
 */
final class PatientNotEligibleForClinicalRecord extends DomainException
{
    public static function merged(string $patientId): self
    {
        return new self(sprintf(
            'Patient [%s] is MERGED — document against the surviving record.',
            $patientId,
        ));
    }

    public static function deceased(string $patientId): self
    {
        return new self(sprintf(
            'Patient [%s] is DECEASED — a new clinical record cannot be originated.',
            $patientId,
        ));
    }

    public static function inactive(string $patientId): self
    {
        return new self(sprintf(
            'Patient [%s] is INACTIVE — reactivate the patient before documenting.',
            $patientId,
        ));
    }

    public function errorCode(): string
    {
        return 'patient_not_eligible_for_clinical_record';
    }
}
