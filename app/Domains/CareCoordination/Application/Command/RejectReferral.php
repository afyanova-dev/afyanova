<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The target facility rejects a referral (26-care-coordination-domain.md §8,
 * §9; ADR-151 D-F). `$reason` is row/audit-only (ADR-136 deny-list), never
 * carried on `ReferralRejected`. Facility-scoped to the receiving facility.
 */
final readonly class RejectReferral implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $referralId,
        public string $actorId,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($referralId)) {
            throw new InvalidArgumentException('RejectReferral::$referralId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RejectReferral::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'reject';
    }

    public function subjectId(): string
    {
        return $this->referralId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'care_coordination.referral_rejected',
            actorId: $this->actorId,
            targetType: 'referral',
            targetId: $this->referralId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
