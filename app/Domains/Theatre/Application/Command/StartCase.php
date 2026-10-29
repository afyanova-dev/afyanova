<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Start a surgical case — entering IN_PROGRESS (17-theatre-domain.md §219;
 * ADR-150 D-D/D-G). The safety-checklist floor (>=2 entries) applies.
 */
final readonly class StartCase implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $caseId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('StartCase::$caseId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('StartCase::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'start';
    }

    public function subjectId(): string
    {
        return $this->caseId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.case_started',
            actorId: $this->actorId,
            targetType: 'surgical_case',
            targetId: $this->caseId,
        );
    }
}
