<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateSurgicalCase` was attempted for a patient whose lifecycle status is
 * not `ACTIVE` (ADR-150 D-H — the `CreateClinicalNote`/ADR-135 patient-state
 * gate precedent). A provisional `ACTIVE` patient IS eligible (ADR-119).
 * Theatre defines its own exception rather than importing Clinical's
 * equivalent (cross-module, only `Contracts` may be imported). Maps to
 * HTTP 409.
 */
final class PatientNotEligibleForSurgicalCase extends DomainException
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
            'Patient [%s] is DECEASED — a new surgical case cannot be originated.',
            $patientId,
        ));
    }

    public static function inactive(string $patientId): self
    {
        return new self(sprintf(
            'Patient [%s] is INACTIVE — reactivate the patient before scheduling surgery.',
            $patientId,
        ));
    }

    public function errorCode(): string
    {
        return 'patient_not_eligible_for_surgical_case';
    }
}
