<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Event;

/**
 * An identifier was recorded against a patient identity (10-patient-domain.md
 * §103, §132; ADR-117). PHI-free (IC §7.1.1): the identifier TYPE is coded,
 * but the identifier VALUE is PHI and is never carried here.
 */
final readonly class PatientIdentifierAdded
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
