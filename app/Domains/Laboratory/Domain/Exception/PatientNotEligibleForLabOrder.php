<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateLabOrder` was attempted for a patient whose lifecycle status is not
 * `ACTIVE` (ADR-157 D-I — the `PatientNotEligibleForReferral` precedent).
 * A provisional `ACTIVE` patient IS eligible (ADR-119). Maps to HTTP 409.
 */
final class PatientNotEligibleForLabOrder extends DomainException
{
    public static function merged(string $patientId): self
    {
        return new self(sprintf('Patient [%s] is MERGED — order against the surviving record.', $patientId));
    }

    public static function deceased(string $patientId): self
    {
        return new self(sprintf('Patient [%s] is DECEASED — a new laboratory order cannot be placed.', $patientId));
    }

    public static function inactive(string $patientId): self
    {
        return new self(sprintf('Patient [%s] is INACTIVE — reactivate the patient before ordering.', $patientId));
    }

    public function errorCode(): string
    {
        return 'patient_not_eligible_for_lab_order';
    }
}
