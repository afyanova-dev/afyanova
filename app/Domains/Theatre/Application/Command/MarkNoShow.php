<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Mark a scheduled surgical case as a no-show (17-theatre-domain.md §219;
 * ADR-150 D-D). No dedicated domain event in T.1 (D-K's 9-event list is
 * REQUESTED/SCHEDULED/READY/IN_PROGRESS/COMPLETED/CANCELLED/POSTPONED/
 * ABORTED only — the same deliberate partial-traversal already accepted for
 * PLANNED/PRE_OP/RECOVERY/CLOSED).
 */
final readonly class MarkNoShow implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $caseId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('MarkNoShow::$caseId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('MarkNoShow::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'markNoShow';
    }

    public function subjectId(): string
    {
        return $this->caseId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.case_marked_no_show',
            actorId: $this->actorId,
            targetType: 'surgical_case',
            targetId: $this->caseId,
        );
    }
}
