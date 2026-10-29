<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The laboratory accepts a received specimen as processable (RECEIVED →
 * ACCEPTED) (14-laboratory-domain.md §156; ADR-158 D-F). No domain event — an
 * internal lab step (the §138 list names none). Advances the order to
 * PROCESSING (the reserved ADR-157 D-E transition) when it is the first
 * specimen accepted.
 */
final readonly class AcceptSpecimen implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $specimenId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($specimenId)) {
            throw new InvalidArgumentException('AcceptSpecimen::$specimenId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AcceptSpecimen::$actorId must be a UUID (IC §3).');
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
            action: 'laboratory.specimen_accepted',
            actorId: $this->actorId,
            targetType: 'lab_specimen',
            targetId: $this->specimenId,
        );
    }
}
