<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Event;

/**
 * A patient identifier was promoted to VERIFIED — entered data is now
 * verified identity evidence (10-patient-domain.md §42, §103, §132; ADR-117).
 * PHI-free (IC §7.1.1): identifiers, a coded type, and temporal fields only.
 */
final readonly class PatientIdentifierVerified
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $patientId,
        public string $identifierId,
        public string $identifierType,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
