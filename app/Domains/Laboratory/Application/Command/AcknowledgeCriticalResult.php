<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Acknowledge a critical laboratory result (INITIATED → ACKNOWLEDGED)
 * (14-laboratory-domain.md §56, §103; ADR-161 D-F). Nominally a
 * Clinical-domain act (§103) — recorded here in Lab.3c pending a Clinical
 * surface (the CC handover-acknowledgement precedent). `$note` is free text,
 * row-only + audit, not in the event.
 */
final readonly class AcknowledgeCriticalResult implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $criticalResultId,
        public string $actorId,
        public ?string $note = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($criticalResultId)) {
            throw new InvalidArgumentException('AcknowledgeCriticalResult::$criticalResultId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AcknowledgeCriticalResult::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'acknowledge';
    }

    public function subjectId(): string
    {
        return $this->criticalResultId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'laboratory.critical_result_acknowledged',
            actorId: $this->actorId,
            targetType: 'lab_critical_result',
            targetId: $this->criticalResultId,
            reason: ($this->note !== null && trim($this->note) !== '') ? trim($this->note) : null,
        );
    }
}
