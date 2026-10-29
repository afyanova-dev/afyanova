<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Abort a surgical case intraoperatively (17-theatre-domain.md §219, §299
 * Invariant 16; ADR-150 D-D) — distinct from cancellation. `$reason` is
 * row/audit-only (ADR-136 deny-list), never carried on `SurgicalCaseAborted`.
 */
final readonly class AbortCase implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $caseId,
        public string $actorId,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('AbortCase::$caseId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AbortCase::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'abort';
    }

    public function subjectId(): string
    {
        return $this->caseId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.case_aborted',
            actorId: $this->actorId,
            targetType: 'surgical_case',
            targetId: $this->caseId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
