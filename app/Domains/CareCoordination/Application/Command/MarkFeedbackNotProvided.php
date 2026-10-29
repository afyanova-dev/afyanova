<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Mark a referral's feedback as not provided after SLA elapses
 * (26-care-coordination-domain.md §16, §7 Invariant 7.7; ADR-151 D-F). The
 * referral itself is unaffected — it may already be COMPLETED.
 */
final readonly class MarkFeedbackNotProvided implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $feedbackId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($feedbackId)) {
            throw new InvalidArgumentException('MarkFeedbackNotProvided::$feedbackId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('MarkFeedbackNotProvided::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'markNotProvided';
    }

    public function subjectId(): string
    {
        return $this->feedbackId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'care_coordination.referral_feedback_not_provided',
            actorId: $this->actorId,
            targetType: 'referral_feedback',
            targetId: $this->feedbackId,
        );
    }
}
