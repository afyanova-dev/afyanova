<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A staff member was added to an encounter's clinical care team
 * (12-clinical-domain.md §4.1; ADR-136, ADR-142 D-R). Written to
 * `shared_outbox_events` in the `AddCareTeamMember` transaction and relayed
 * asynchronously; idempotent consumers key on `eventId`. NOT broadcast.
 *
 * When the same `AddCareTeamMember` also designates the new membership as the
 * encounter's first provider-of-record, a {@see ProviderOfRecordChanged} event
 * (with `previousProviderStaffUserId = null`) is emitted alongside this one, in
 * the same transaction (ADR-142 D-R).
 *
 * STRICTLY PHI-FREE (IC §7.1.1; ADR-136; ADR-142 D-R): identifiers — including
 * `staffUserId`, a STAFF identifier in the same non-PHI class as `actorId` /
 * `author_user_id` (the `PatientDuplicateDetected::$attemptedByUserId`
 * precedent) — plus the coded `teamRole`, the boolean `isProviderOfRecord` and
 * the envelope `occurredAt`. NO `end_reason`, NO patient demographics, NO staff
 * name / credentials, NO clinical narrative.
 */
final readonly class CareTeamMemberAdded
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
        public bool $isProviderOfRecord,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
