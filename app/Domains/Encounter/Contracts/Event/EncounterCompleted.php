<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Contracts\Event;

/**
 * An attendance was closed — `ACTIVE → COMPLETED` (11-encounter-domain.md §19,
 * §22, §70, §73, §103; ADR-121, ADR-130). The encounter's own lifecycle is
 * complete; this does NOT mean every downstream workflow is complete
 * (Invariant 18; §136–§139). Consumers (Billing, Reporting, Notifications, Care
 * Coordination, Bed & Ward Management) determine their own response — bed release
 * on discharge is Bed & Ward's, off this event (ADR-107; §79).
 *
 * Carries the coded `disposition` — the completion outcome, always non-null (a
 * recorded disposition is the precondition for completion). Reporting KPIs
 * (follow-up / referral rates — §156) are disposition aggregations and there is
 * no `EncounterApi` accessor for it (R-4).
 *
 * PHI-free (IMPLEMENTATION-CONTRACTS.md §7.1.1; §187 / §188): identifiers plus
 * the single coded `disposition` value — no patient name, number, date of birth,
 * phone, address, previous status, encounter type, completion timestamp beyond
 * the envelope `occurredAt`, reason, or clinical detail.
 */
final readonly class EncounterCompleted
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
