<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * End one care-team membership — `ACTIVE → ENDED` (12-clinical-domain.md §4.1;
 * ADR-142 D-I / D-N / D-O / D-R / D-S).
 *
 * The membership transitions to the terminal ENDED state (`active_to` set once,
 * never rewritten); ENDED has no reactivation. If the membership is the
 * encounter's provider-of-record, the designation is cleared (the encounter is
 * then left with none — valid, ADR-142 D-G) and a `ProviderOfRecordChanged`
 * event is emitted alongside `CareTeamMemberRemoved`.
 *
 * `$reason` (optional) is the membership-end rationale — it is stored on the
 * `clin_care_team_memberships` row (`end_reason`) ONLY. It is NEVER emitted in
 * an event and NEVER copied into the audit entry (ADR-142 D-H / D-R / D-S — the
 * audit `reason` is always null). There is NO caller-supplied change timestamp:
 * `active_to` is server `now()`.
 *
 * Per-subject authorization: the bus loads the membership and checks
 * `CareTeamMembershipPolicy::end` (the `clinical.care_team.record` key at the
 * membership's ORIGINATING facility — ADR-142 D-N). NO `PatientApi` /
 * `EncounterApi` call (the post-origination principle — ADR-142 D-K / D-L).
 */
final readonly class EndCareTeamMembership implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $careTeamMembershipId,
        public string $actorId,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['careTeamMembershipId' => $careTeamMembershipId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("EndCareTeamMembership::\${$name} must be a UUID (IC §3).");
            }
        }
    }

    public function policyAbility(): string
    {
        return 'end';
    }

    public function subjectId(): string
    {
        return $this->careTeamMembershipId; // per-subject — the bus loads the membership for the `end` policy check (IC §5.1)
    }

    /** The trimmed membership-end reason, or null. Persisted to `end_reason` — never to audit / events (ADR-142 D-H). */
    public function endReason(): ?string
    {
        return ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.care_team.member_removed',
            actorId: $this->actorId,
            targetType: 'care_team_membership',
            targetId: $this->careTeamMembershipId,
            reason: null, // ADR-142 D-S — metadata = [], reason = null always; end_reason lives on the row only
        );
    }
}
