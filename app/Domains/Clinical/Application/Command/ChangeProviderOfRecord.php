<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Change (or first-assign) an encounter's provider-of-record
 * (12-clinical-domain.md §4.1 "changing it is an explicit, audited event";
 * ADR-142 D-G / D-H / D-J / D-N / D-O / D-R / D-S).
 *
 * ONE parameterized command covers BOTH a first assignment (when the encounter
 * has no active provider-of-record — the event carries
 * `previousProviderStaffUserId = null`) AND a hand-off between two active
 * members (the `VerifyAllergy(CONFIRMED|REFUTED)` "one command, one mechanism"
 * precedent). The incoming `$newProviderStaffUserId` MUST already hold an
 * ACTIVE membership on the encounter (else 409 — ADR-142 D-G); the outgoing
 * provider-of-record remains an active team member. This command CREATES no
 * membership.
 *
 * There is **NO `reason` parameter** and NO rationale field anywhere — C.6
 * captures no provider-of-record-change rationale (ADR-142 D-H / reconciliation
 * R-1). The state transition is auditable through the `ProviderOfRecordChanged`
 * event + the `clinical.care_team.provider_of_record_changed` audit row; the
 * ordered stream is the lossless assignment history.
 *
 * Class-level authorization: the bus checks
 * `CareTeamMembershipPolicy::changeProviderOfRecord`. The handler applies the
 * ADR-142 D-N refinement against the encounter's memberships' shared
 * originating `facility_id` (no `getEncounterContext` call — the
 * post-origination principle).
 */
final readonly class ChangeProviderOfRecord implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $encounterId,
        public string $actorId,
        public string $newProviderStaffUserId,
        public ?string $idempotencyKey = null,
    ) {
        foreach ([
            'encounterId' => $encounterId,
            'actorId' => $actorId,
            'newProviderStaffUserId' => $newProviderStaffUserId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("ChangeProviderOfRecord::\${$name} must be a UUID (IC §3).");
            }
        }
    }

    public function policyAbility(): string
    {
        return 'changeProviderOfRecord';
    }

    public function subjectId(): ?string
    {
        return null; // class-level check (IC §5.1) — keyed by encounter, not a single membership id
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.care_team.provider_of_record_changed',
            actorId: $this->actorId,
            targetType: 'encounter',
            targetId: $this->encounterId,
            reason: null, // ADR-142 D-S — metadata = [], reason = null always
        );
    }
}
