<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Event;

/**
 * A patient identity was created (10-patient-domain.md §92; ADR-114, ADR-116).
 *
 * PHI-free (IMPLEMENTATION-CONTRACTS.md §7.1.1): identifiers, a status flag,
 * and coded/temporal fields only — no name, date of birth, phone, or address.
 * A consumer that needs demographic detail queries `PatientApi` for it.
 */
final readonly class PatientRegistered
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $patientId,
        public string $patientNumber,
        public string $facilityId,
        public bool $isProvisional,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
