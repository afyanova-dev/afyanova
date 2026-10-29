<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The receiving side acknowledges a recovery handoff (17-theatre-domain.md
 * §90–§92; ADR-156 D-D). Needs `theatre.recovery.acknowledge` — a genuinely
 * different actor/facility scope than the initiator's `theatre.recovery.handoff`
 * (the CP.1 multi-actor precedent).
 */
final readonly class AcknowledgeRecoveryHandoff implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $handoffId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($handoffId)) {
            throw new InvalidArgumentException('AcknowledgeRecoveryHandoff::$handoffId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AcknowledgeRecoveryHandoff::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'acknowledge';
    }

    public function subjectId(): string
    {
        return $this->handoffId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.recovery_handoff_acknowledged',
            actorId: $this->actorId,
            targetType: 'recovery_handoff',
            targetId: $this->handoffId,
        );
    }
}
