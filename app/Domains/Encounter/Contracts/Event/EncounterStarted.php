<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Contracts\Event;

/**
 * Care has begun on an arrived encounter — `ARRIVED → ACTIVE`
 * (11-encounter-domain.md §19, §72; ADR-121, ADR-130). The Encounter domain
 * remains authoritative for encounter state; downstream consumers (Clinical,
 * Billing, Reporting, Patient Flow) determine their own response (§72).
 *
 * PHI-free (IMPLEMENTATION-CONTRACTS.md §7.1.1; §187 / §188): identifiers and
 * coded/temporal fields only — no patient name, number, date of birth, phone,
 * address, reason, or clinical detail.
 */
final readonly class EncounterStarted
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
