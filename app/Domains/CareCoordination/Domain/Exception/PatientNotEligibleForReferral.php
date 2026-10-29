<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateReferral` was attempted for a patient whose lifecycle status is not
 * `ACTIVE` (ADR-151 D-G — the `CreateClinicalNote`/ADR-135 patient-state gate
 * precedent). A provisional `ACTIVE` patient IS eligible (ADR-119). Care
 * Coordination defines its own exception rather than importing another
 * module's equivalent (cross-module, only `Contracts` may be imported).
 * Maps to HTTP 409.
 */
final class PatientNotEligibleForReferral extends DomainException
{
    public static function merged(string $patientId): self
    {
        return new self(sprintf(
            'Patient [%s] is MERGED — refer against the surviving record.',
            $patientId,
        ));
    }

    public static function deceased(string $patientId): self
    {
        return new self(sprintf(
            'Patient [%s] is DECEASED — a new referral cannot be originated.',
            $patientId,
        ));
    }

    public static function inactive(string $patientId): self
    {
        return new self(sprintf(
            'Patient [%s] is INACTIVE — reactivate the patient before referring.',
            $patientId,
        ));
    }

    public function errorCode(): string
    {
        return 'patient_not_eligible_for_referral';
    }
}
