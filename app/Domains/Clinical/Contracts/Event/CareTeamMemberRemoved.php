<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A staff member's care-team membership was ended (`ACTIVE → ENDED`)
 * (12-clinical-domain.md §4.1; ADR-136, ADR-142 D-R). Written to
 * `shared_outbox_events` in the `EndCareTeamMembership` transaction and relayed
 * asynchronously; idempotent consumers key on `eventId`. NOT broadcast.
 *
 * When the ended membership was the encounter's provider-of-record, a
 * {@see ProviderOfRecordChanged} event (with `newProviderStaffUserId = null` —
 * the encounter is left with no provider-of-record, which is valid) is emitted
 * alongside this one, in the same transaction (ADR-142 D-J / D-R).
 *
 * Care Coordination consumes this in PHASE 7 ("coordinator's care-team
 * membership ends → episode flagged" — `26` §379); no consumer ships in C.6.
 *
 * STRICTLY PHI-FREE (IC §7.1.1; ADR-136; ADR-142 D-R): identifiers (incl. the
 * staff identifier `staffUserId`), the coded `teamRole`, the boolean
 * `wasProviderOfRecord` and the envelope `occurredAt`. The membership-end
 * `end_reason` is NEVER carried here — it lives on the membership row only
 * (ADR-142 D-H). NO demographics, NO staff name, NO clinical narrative.
 */
final readonly class CareTeamMemberRemoved
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $careTeamMembershipId,
        public string $staffUserId,
        public string $teamRole,
        public bool $wasProviderOfRecord,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
