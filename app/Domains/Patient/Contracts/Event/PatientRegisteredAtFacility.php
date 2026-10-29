<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Event;

/**
 * A patient's facility participation was recorded (10-patient-domain.md
 * §109-111; ADR-114). PHI-free (IC §7.1.1): identifiers and temporal fields
 * only.
 */
final readonly class PatientRegisteredAtFacility
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $patientId,
        public string $facilityId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
