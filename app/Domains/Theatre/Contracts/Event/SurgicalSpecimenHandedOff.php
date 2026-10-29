<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * A surgical specimen was handed off to a laboratory (17-theatre-domain.md
 * §73–§74, §210, §289; ADR-164 D-E). PHI-safe: identifiers + the coded
 * `destinationLaboratory` only.
 */
final readonly class SurgicalSpecimenHandedOff
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $surgicalSpecimenId,
        public string $caseId,
        public string $patientId,
        public string $destinationLaboratory,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
