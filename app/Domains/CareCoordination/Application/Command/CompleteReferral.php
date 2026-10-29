<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The target facility completes the referred episode of care
 * (26-care-coordination-domain.md §8, §9; ADR-151 D-F). A COMPLETED referral
 * may never receive feedback thereafter (Invariant 7.7) — this command does
 * not touch `ReferralFeedback`. Facility-scoped to the receiving facility.
 */
final readonly class CompleteReferral implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $referralId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($referralId)) {
            throw new InvalidArgumentException('CompleteReferral::$referralId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CompleteReferral::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'complete';
    }

    public function subjectId(): string
    {
        return $this->referralId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'care_coordination.referral_completed',
            actorId: $this->actorId,
            targetType: 'referral',
            targetId: $this->referralId,
        );
    }
}
