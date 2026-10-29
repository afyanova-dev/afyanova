<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record receipt of a collected specimen at the laboratory (COLLECTED →
 * RECEIVED) (14-laboratory-domain.md §31, §156; ADR-158 D-F). The actor is
 * recorded as the receiver (§31 "Received By").
 */
final readonly class ReceiveSpecimen implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $specimenId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($specimenId)) {
            throw new InvalidArgumentException('ReceiveSpecimen::$specimenId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ReceiveSpecimen::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'receive';
    }

    public function subjectId(): string
    {
        return $this->specimenId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'laboratory.specimen_received',
            actorId: $this->actorId,
            targetType: 'lab_specimen',
            targetId: $this->specimenId,
        );
    }
}
