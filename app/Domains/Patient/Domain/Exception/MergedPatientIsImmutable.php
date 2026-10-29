<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A write was attempted against a patient whose status is MERGED
 * (10-patient-domain.md Invariant 14; ADR-128 M-9). A merged identity cannot
 * independently gain or change records — demographics, identifiers, contacts,
 * addresses, relationships and facility registrations are all blocked.
 * `RequestUnmerge` is the only governed operation on a MERGED patient. Maps to
 * HTTP 409 (§15).
 */
final class MergedPatientIsImmutable extends DomainException
{
    public static function for(string $patientId): self
    {
        return new self(sprintf(
            'Patient [%s] is MERGED and cannot be independently modified (Invariant 14).',
            $patientId,
        ));
    }

    public function errorCode(): string
    {
        return 'merged_patient_is_immutable';
    }
}
