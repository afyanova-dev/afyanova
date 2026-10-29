<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Contracts\Event;

/**
 * The coarse care-context location of an encounter was set or changed —
 * `department_id` and/or `unit_id`, the §177 "Core Location"
 * (11-encounter-domain.md §36, §37, §38, §70, §103; ADR-121, ADR-130 B). The
 * encounter's status is unchanged. This is care-context only: it is NOT a bed
 * assignment (Bed & Ward Management, ADR-107), NOT a room-level operational
 * move (Queueing & Patient Flow, doc 10 §6), NOT a queue assignment (§165).
 * Consumers (Queueing, Bed & Ward, Reporting, Patient Flow) determine their own
 * response.
 *
 * PHI-free (IMPLEMENTATION-CONTRACTS.md §7.1.1; §187 / §188; ADR-130 B —
 * "encounter id + coded/id location fields"): identifiers plus the current
 * (post-assignment) `departmentId` / `unitId` — no patient name, number, date of
 * birth, phone, address, previous location, reason, room / bed, or clinical
 * detail.
 */
final readonly class EncounterLocationChanged
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $encounterId,
        public string $patientId,
        public ?string $departmentId,
        public ?string $unitId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
