<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Contracts\Event;

/**
 * An encounter (one patient attendance at one facility) was created — the
 * authoritative check-in (11-encounter-domain.md §71; ADR-101, ADR-104,
 * ADR-130).
 *
 * PHI-free (IMPLEMENTATION-CONTRACTS.md §7.1.1; §187 / §188): identifiers, a
 * human-readable number, and coded/temporal fields only — no patient name, date
 * of birth, phone, address, or clinical detail. A consumer that needs
 * demographic detail queries `PatientApi`; one that needs encounter detail
 * queries `EncounterApi`.
 */
final readonly class EncounterCreated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $encounterId,
        public string $patientId,
        public string $encounterNumber,
        public string $encounterType,
        public string $status,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
