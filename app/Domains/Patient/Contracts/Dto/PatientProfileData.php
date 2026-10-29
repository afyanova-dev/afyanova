<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Dto;

/**
 * Rich 360° read-model for the Patient Profile workspace (UI-SPECIFICATIONS.md §11.2.3).
 *
 * Assembled by PatientReader within tenant scope (RLS).
 */
final readonly class PatientProfileData
{
    /**
     * @param  list<PatientGlanceIdentifier>  $identifiers
     * @param  list<array{id: string, type: string, region: ?string, district: ?string, ward: ?string, streetOrVillage: ?string, postalCode: ?string}>  $addresses
     * @param  list<array{id: string, role: string, name: string, phone: ?string, label: ?string}>  $relationships
     * @param  list<array{action: string, actorName: ?string, reason: ?string, changedFields: list<string>, changedAt: string}>  $auditHistory
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
        public array $addresses = [],
        public array $relationships = [],
        public array $auditHistory = [],
        public ?string $photoDocumentId = null,
    ) {}
}
