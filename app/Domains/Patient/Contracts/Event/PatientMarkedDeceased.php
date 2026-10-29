<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Event;

/**
 * A patient identity was recorded as deceased at the identity level
 * (10-patient-domain.md §45, §132; ADR-116). Clinical documentation of the
 * death belongs to Clinical / Encounter (§45), so this event carries no
 * date/cause of death — identifiers and temporal fields only (IC §7.1.1).
 */
final readonly class PatientMarkedDeceased
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
