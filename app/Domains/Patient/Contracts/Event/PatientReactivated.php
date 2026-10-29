<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Event;

/**
 * A patient identity was moved back to ACTIVE from INACTIVE
 * (10-patient-domain.md §132; ADR-116). PHI-free (IC §7.1.1).
 */
final readonly class PatientReactivated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $patientId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
