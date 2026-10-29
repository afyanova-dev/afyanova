<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * The encounter's provider-of-record changed (12-clinical-domain.md §4.1
 * "changing it is an explicit, audited event"; ADR-136, ADR-142 D-H / D-J /
 * D-R). Written to `shared_outbox_events` in the same transaction as the
 * command that caused it and relayed asynchronously; idempotent consumers key
 * on `eventId`. NOT broadcast.
 *
 * Emitted by:
 *   - `ChangeProviderOfRecord` — a hand-off between two active members
 *     (`previous` → `new`); or a first assignment when the encounter had no
 *     active provider-of-record (`previousProviderStaffUserId = null`);
 *   - `AddCareTeamMember(isProviderOfRecord: true)` — first assignment alongside
 *     {@see CareTeamMemberAdded} (`previousProviderStaffUserId = null`);
 *   - `EndCareTeamMembership` of the current provider-of-record — the
 *     designation is cleared alongside {@see CareTeamMemberRemoved}
 *     (`newProviderStaffUserId = null`; the encounter is left with none).
 *
 * The ordered stream of these events plus the
 * `clinical.care_team.provider_of_record_changed` audit rows is the LOSSLESS
 * provider-of-record assignment history — C.6 has NO provider-of-record history
 * table and NO change rationale (ADR-142 D-H / reconciliation R-1).
 *
 * STRICTLY PHI-FREE (IC §7.1.1; ADR-136; ADR-142 D-R): identifiers only (the
 * staff identifiers `previousProviderStaffUserId` / `newProviderStaffUserId`,
 * the membership ids, and the tenant / facility / patient / encounter ids) plus
 * the envelope `occurredAt`. There is NO reason / rationale field anywhere on
 * this event (ADR-142 D-H). NO demographics, NO staff name, NO clinical
 * narrative.
 */
final readonly class ProviderOfRecordChanged
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public ?string $previousProviderStaffUserId,
        public ?string $previousProviderMembershipId,
        public ?string $newProviderStaffUserId,
        public ?string $newProviderMembershipId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
