<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The target facility acknowledges receipt of a referral
 * (26-care-coordination-domain.md §8, §9; ADR-151 D-F). Facility-scoped to
 * the receiving facility (§14).
 */
final readonly class ReceiveReferral implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $referralId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($referralId)) {
            throw new InvalidArgumentException('ReceiveReferral::$referralId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ReceiveReferral::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'receive';
    }

    public function subjectId(): string
    {
        return $this->referralId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'care_coordination.referral_received',
            actorId: $this->actorId,
            targetType: 'referral',
            targetId: $this->referralId,
        );
    }
}
