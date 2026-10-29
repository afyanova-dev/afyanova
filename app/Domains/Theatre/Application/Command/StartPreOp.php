<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Move a scheduled case into PRE_OP (17-theatre-domain.md §219; ADR-150 D-D).
 */
final readonly class StartPreOp implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $caseId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('StartPreOp::$caseId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('StartPreOp::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'startPreOp';
    }

    public function subjectId(): string
    {
        return $this->caseId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.case_pre_op_started',
            actorId: $this->actorId,
            targetType: 'surgical_case',
            targetId: $this->caseId,
        );
    }
}
