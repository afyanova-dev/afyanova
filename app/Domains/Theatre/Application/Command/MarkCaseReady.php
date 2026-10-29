<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Mark a surgical case READY — the safety-checklist gate applies
 * (17-theatre-domain.md §51 Invariant; §299 Invariant 13; ADR-150 D-D/D-E/D-G).
 */
final readonly class MarkCaseReady implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $caseId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('MarkCaseReady::$caseId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('MarkCaseReady::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'markReady';
    }

    public function subjectId(): string
    {
        return $this->caseId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.case_marked_ready',
            actorId: $this->actorId,
            targetType: 'surgical_case',
            targetId: $this->caseId,
        );
    }
}
