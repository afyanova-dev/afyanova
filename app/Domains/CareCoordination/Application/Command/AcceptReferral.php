<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The target facility accepts a referral (26-care-coordination-domain.md §8,
 * §9; ADR-151 D-F). Also creates the referral's one CC.1 `ReferralFeedback`
 * row (sequence=1, EXPECTED) — an accepted referral implies feedback is now
 * owed back to the referrer (Invariant 7.7's independent feedback
 * lifecycle). Facility-scoped to the receiving facility.
 */
final readonly class AcceptReferral implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $referralId,
        public string $feedbackId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($referralId)) {
            throw new InvalidArgumentException('AcceptReferral::$referralId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($feedbackId)) {
            throw new InvalidArgumentException('AcceptReferral::$feedbackId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AcceptReferral::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'accept';
    }

    public function subjectId(): string
    {
        return $this->referralId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'care_coordination.referral_accepted',
            actorId: $this->actorId,
            targetType: 'referral',
            targetId: $this->referralId,
            metadata: [
                'feedback_id' => $this->feedbackId,
            ],
        );
    }
}
