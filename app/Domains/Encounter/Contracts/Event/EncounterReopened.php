<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Contracts\Event;

/**
 * A completed encounter was reopened — `COMPLETED → ACTIVE`
 * (11-encounter-domain.md §19, §68, §70, §103; ADR-121, ADR-130, Rulings E8-R1..E8-R8).
 * The encounter is active again; downstream consumers (Clinical, Billing,
 * Reporting, Patient Flow) determine their own response.
 *
 * PHI-free (IMPLEMENTATION-CONTRACTS.md §7.1.1; §187 / §188): identifiers and
 * temporal fields only — no patient name, number, date of birth, phone,
 * address, reason, disposition, or clinical detail. A consumer that needs detail
 * queries `EncounterApi` / `PatientApi`.
 */
final readonly class EncounterReopened
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
