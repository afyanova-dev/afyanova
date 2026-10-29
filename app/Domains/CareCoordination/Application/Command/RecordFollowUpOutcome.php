<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\Model\FollowUp;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record the outcome of a PENDING follow-up (26-care-coordination-domain.md
 * §8, §9; ADR-154 D-E). `$outcome` is one of KEPT | MISSED | CANCELLED |
 * NOT_REQUIRED. KEPT REQUIRES `$resultingEncounterId` (Invariant 7.9).
 * `$note` is audit-only (ADR-136 deny-list) — never on an event.
 */
final readonly class RecordFollowUpOutcome implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $followUpId,
        public string $actorId,
        public string $outcome,
        public ?string $resultingEncounterId = null,
        public ?string $note = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($followUpId)) {
            throw new InvalidArgumentException('RecordFollowUpOutcome::$followUpId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RecordFollowUpOutcome::$actorId must be a UUID (IC §3).');
        }

        if (! in_array($outcome, FollowUp::OUTCOMES, true)) {
            throw new InvalidArgumentException('RecordFollowUpOutcome::$outcome must be one of KEPT|MISSED|CANCELLED|NOT_REQUIRED.');
        }

        if ($resultingEncounterId !== null && ! Str::isUuid($resultingEncounterId)) {
            throw new InvalidArgumentException('RecordFollowUpOutcome::$resultingEncounterId must be a UUID or null (IC §3).');
        }

        if ($outcome === FollowUp::STATUS_KEPT && $resultingEncounterId === null) {
            throw new InvalidArgumentException('RecordFollowUpOutcome::$resultingEncounterId is required when $outcome is KEPT (Invariant 7.9).');
        }

        if ($outcome !== FollowUp::STATUS_KEPT && $resultingEncounterId !== null) {
            throw new InvalidArgumentException('RecordFollowUpOutcome::$resultingEncounterId must be null unless $outcome is KEPT.');
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->followUpId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'care_coordination.followup_outcome_recorded',
            actorId: $this->actorId,
            targetType: 'follow_up',
            targetId: $this->followUpId,
            reason: ($this->note !== null && trim($this->note) !== '') ? trim($this->note) : null,
            metadata: [
                'outcome' => $this->outcome,
            ],
        );
    }
}
