<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Contracts\Event;

/**
 * The patient is physically present against a pre-created `PLANNED` encounter —
 * `PLANNED → ARRIVED` (11-encounter-domain.md §19, §70; ADR-121, ADR-130).
 *
 * PHI-free (IMPLEMENTATION-CONTRACTS.md §7.1.1; §187 / §188): identifiers and
 * coded/temporal fields only — no patient name, number, date of birth, phone,
 * address, reason, or clinical detail. A consumer that needs detail queries
 * `EncounterApi` / `PatientApi`.
 */
final readonly class EncounterArrived
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $encounterId,
        public string $patientId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
