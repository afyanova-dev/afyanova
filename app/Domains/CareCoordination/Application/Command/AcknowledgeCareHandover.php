<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The receiving team accepts ongoing responsibility for a handover referral
 * (26-care-coordination-domain.md §9; ADR-151 D-F). Requires
 * `implies_handover` and an ACCEPTED referral. Its own dedicated permission
 * key (`care_coordination.handover.acknowledge`), facility-scoped to the receiving facility.
 */
final readonly class AcknowledgeCareHandover implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $referralId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($referralId)) {
            throw new InvalidArgumentException('AcknowledgeCareHandover::$referralId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AcknowledgeCareHandover::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'acknowledgeHandover';
    }

    public function subjectId(): string
    {
        return $this->referralId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'care_coordination.care_handover_acknowledged',
            actorId: $this->actorId,
            targetType: 'referral',
            targetId: $this->referralId,
        );
    }
}
