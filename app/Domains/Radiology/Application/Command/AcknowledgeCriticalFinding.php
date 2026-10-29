<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Acknowledge a critical imaging finding (INITIATED → ACKNOWLEDGED)
 * (15-radiology-domain.md §100, §167, §213; ADR-169 D-E). Recorded
 * Radiology-side pending a Clinical acknowledgement surface (§100 / §167 — the
 * Lab.3c / CC handover-acknowledgement precedent). `$note` is free text,
 * ROW-ONLY (ADR-136).
 */
final readonly class AcknowledgeCriticalFinding implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $criticalFindingId,
        public string $actorId,
        public ?string $note = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($criticalFindingId)) {
            throw new InvalidArgumentException('AcknowledgeCriticalFinding::$criticalFindingId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AcknowledgeCriticalFinding::$actorId must be a UUID (IC §3).');
        }

        if ($note !== null && trim($note) === '') {
            throw new InvalidArgumentException('AcknowledgeCriticalFinding::$note must be a non-blank string or null.');
        }
    }

    public function policyAbility(): string
    {
        return 'acknowledge';
    }

    public function subjectId(): string
    {
        return $this->criticalFindingId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'radiology.critical_finding_acknowledged',
            actorId: $this->actorId,
            targetType: 'radiology_critical_finding',
            targetId: $this->criticalFindingId,
        );
    }
}
