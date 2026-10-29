<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Dto;

/**
 * Rich read-model for the Patient Glance preview (Presentation).
 *
 * Owned by Patient; assembled by the Patient reader within the current tenant
 * scope (RLS). Identifiers are limited to VERIFIED rows at read time; the
 * caller's view_sensitive permission is applied in the HTTP layer, never here.
 */
final readonly class PatientGlance
{
    /**
     * @param  list<PatientGlanceIdentifier>  $identifiers
     */
    public function __construct(
        public string $patientId,
        public string $patientNumber,
        public string $displayName,
        public string $givenName,
        public string $familyName,
        public ?string $middleName,
        public string $sex,
        public string $status,
        public bool $isProvisional,
        public ?string $dateOfBirth,
        public string $dobPrecision,
        public ?string $primaryPhone,
        public ?string $email,
        public ?string $registrationSource,
        public ?string $registeredAt,
        public bool $hasAccessibleFacilityRegistration,
        public int $facilityRegistrationCount,
        public ?string $survivingPatientId,
        public array $identifiers,
    ) {}
}
