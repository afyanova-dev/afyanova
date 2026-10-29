<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Finalize a referral's feedback thread (26-care-coordination-domain.md §8,
 * §9; ADR-151 D-F) — closes the loop, no new content.
 */
final readonly class CompleteReferralFeedback implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $feedbackId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($feedbackId)) {
            throw new InvalidArgumentException('CompleteReferralFeedback::$feedbackId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CompleteReferralFeedback::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'complete';
    }

    public function subjectId(): string
    {
        return $this->feedbackId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'care_coordination.referral_feedback_completed',
            actorId: $this->actorId,
            targetType: 'referral_feedback',
            targetId: $this->feedbackId,
        );
    }
}
