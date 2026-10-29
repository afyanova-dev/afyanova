<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A patient already has an active facility registration
 * (pat_patient_registrations UNIQUE (tenant_id, facility_id, patient_id) —
 * ADR-114). IC §9.1: the handler catches the unique violation and rethrows
 * this DomainException rather than the raw QueryException.
 */
final class PatientAlreadyRegisteredAtFacility extends DomainException
{
    public static function forPatientAndFacility(string $patientId, string $facilityId): self
    {
        return new self(sprintf(
            'Patient [%s] is already registered at facility [%s].',
            $patientId,
            $facilityId,
        ));
    }

    public function errorCode(): string
    {
        return 'patient_already_registered_at_facility';
    }
}
