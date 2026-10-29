<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Contracts\Event;

/**
 * An encounter was transferred out to another facility — `ACTIVE → TRANSFERRED`
 * (11-encounter-domain.md §25, §69, §70, §75, §90, §91, §103, §169, §187, §188;
 * ADR-121, ADR-130). This attendance has ended; its terminal status is
 * `TRANSFERRED` and its disposition is `TRANSFERRED` (ADR-130 A). The receiving
 * facility opens its OWN separate encounter through its own check-in (§8, §91) —
 * this event does NOT represent that encounter and carries no `TRANSFERRED_FROM`
 * link (ADR-130 Deferred; cross-encounter linking is Care Coordination's).
 * Terminal event. Written to `shared_outbox_events` in the command transaction
 * and relayed asynchronously; no synchronous downstream domain calls. Consumers
 * (Bed & Ward Management — end occupancy with reason `TRANSFER`; Billing;
 * Reporting; Care Coordination) determine their own response.
 *
 * STRICTLY PHI-FREE (IMPLEMENTATION-CONTRACTS.md §7.1.1; §187 / §188): carries
 * ONLY system identifiers and event metadata. The outcome is structurally fixed
 * as `TRANSFERRED`, so no `status` / `disposition` discriminator is carried
 * (mirrors `EncounterAdmitted` / `EncounterReopened` / `EncounterCancelled`). No
 * destination facility / department / unit, no reason, no previous status, no
 * location, no encounter type, no patient demographics, no clinical detail.
 */
final readonly class EncounterTransferred
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
