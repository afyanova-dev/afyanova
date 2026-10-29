<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `OpenCareEpisode` was attempted for a patient whose lifecycle status is not
 * `ACTIVE` (ADR-153 D-I — the `PatientNotEligibleForReferral` precedent). A
 * provisional `ACTIVE` patient IS eligible (ADR-119). Maps to HTTP 409.
 */
final class PatientNotEligibleForCareEpisode extends DomainException
{
    public static function merged(string $patientId): self
    {
        return new self(sprintf(
            'Patient [%s] is MERGED — open the episode against the surviving record.',
            $patientId,
        ));
    }

    public static function deceased(string $patientId): self
    {
        return new self(sprintf(
            'Patient [%s] is DECEASED — a new care episode cannot be opened.',
            $patientId,
        ));
    }

    public static function inactive(string $patientId): self
    {
        return new self(sprintf(
            'Patient [%s] is INACTIVE — reactivate the patient before opening a care episode.',
            $patientId,
        ));
    }

    public function errorCode(): string
    {
        return 'patient_not_eligible_for_care_episode';
    }
}
