<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Contracts\Event;

/**
 * The disposition of an active encounter was recorded or revised — what happened
 * (or will happen) to the patient at the end of the attendance
 * (11-encounter-domain.md §70, §76, §77, §103; ADR-121, ADR-130 A). The
 * encounter's status is unchanged (`ACTIVE`). Consumers (Billing, Reporting,
 * Care Coordination, Patient Flow) determine their own response: a `REFERRED`
 * disposition does NOT create a referral record — that is Care Coordination's
 * (§168) — and `DISCHARGED` does not release a bed — that is Bed & Ward
 * Management's, off `EncounterCompleted` (ADR-107).
 *
 * PHI-free (IMPLEMENTATION-CONTRACTS.md §7.1.1; §187 / §188): identifiers plus
 * the single coded `disposition` value — no patient name, number, date of birth,
 * phone, address, previous disposition, reason, location, or clinical detail. A
 * consumer that needs the disposition reads it here; there is no earlier event
 * or `EncounterApi` accessor for it.
 */
final readonly class EncounterDispositionChanged
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $encounterId,
        public string $patientId,
        public string $disposition,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
