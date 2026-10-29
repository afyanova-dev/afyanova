<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Exception;

use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Shared\Domain\DomainException;

/**
 * A `MergePatients` / `RequestUnmerge` request violates the ADR-128 M-7 rules
 * (self-merge, an ineligible survivor / non-survivor state, or a patient that
 * is not currently merged). Maps to HTTP 409 (§15).
 */
final class PatientMergeRejected extends DomainException
{
    public static function cannotMergeWithSelf(): self
    {
        return new self('A patient cannot be merged with itself (Invariant 13).');
    }

    public static function survivorNotEligible(PatientStatus $status): self
    {
        return new self(sprintf(
            'The surviving patient must be ACTIVE or INACTIVE; it is %s.',
            $status->value,
        ));
    }

    public static function nonSurvivorNotEligible(PatientStatus $status): self
    {
        return new self(sprintf(
            'The non-surviving patient must be ACTIVE or INACTIVE; it is %s.',
            $status->value,
        ));
    }

    public static function notCurrentlyMerged(string $patientId): self
    {
        return new self(sprintf(
            'Patient [%s] has no active merge to reverse.',
            $patientId,
        ));
    }

    public function errorCode(): string
    {
        return 'patient_merge_rejected';
    }
}
