<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Contracts\Event;

/**
 * An active OUTPATIENT / EMERGENCY encounter was admitted as an inpatient — the
 * one sanctioned in-place type conversion (11-encounter-domain.md §14.1, §78,
 * §103; ADR-108, ADR-121, ADR-130). The encounter's status stays `ACTIVE`; its
 * type is now `INPATIENT` and `admitted_at` is set. Consumers (Bed & Ward
 * Management, Billing, Reporting, Patient Flow) determine their own response;
 * bed occupancy is Bed & Ward's (ADR-107), not signalled here.
 *
 * PHI-free (IMPLEMENTATION-CONTRACTS.md §7.1.1; §187 / §188): identifiers and
 * temporal fields only — no encounter type, disposition, previous type, reason,
 * location, or clinical detail. A consumer that needs the current type queries
 * `EncounterApi`; the original type is on the earlier `EncounterCreated` event
 * for the same `encounterId`.
 */
final readonly class EncounterAdmitted
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
