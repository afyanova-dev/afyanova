<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `ScheduleFollowUp` was attempted for a patient whose lifecycle status is
 * not `ACTIVE` (ADR-154 D-I — the `PatientNotEligibleForReferral` precedent).
 * A provisional `ACTIVE` patient IS eligible (ADR-119). Maps to HTTP 409.
 */
final class PatientNotEligibleForFollowUp extends DomainException
{
    public static function merged(string $patientId): self
    {
        return new self(sprintf('Patient [%s] is MERGED — schedule against the surviving record.', $patientId));
    }

    public static function deceased(string $patientId): self
    {
        return new self(sprintf('Patient [%s] is DECEASED — a new follow-up cannot be scheduled.', $patientId));
    }

    public static function inactive(string $patientId): self
    {
        return new self(sprintf('Patient [%s] is INACTIVE — reactivate the patient before scheduling a follow-up.', $patientId));
    }

    public function errorCode(): string
    {
        return 'patient_not_eligible_for_follow_up';
    }
}
