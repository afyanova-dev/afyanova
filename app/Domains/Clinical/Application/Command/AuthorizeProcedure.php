<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Authorize a requested procedure for execution — `REQUESTED → AUTHORIZED`
 * (13-clinical-procedures-domain.md §21; ADR-146 D-F). Always an explicit
 * transition (13 §21 is emphatic) — there is no auto-authorize path in CP.1.
 * NOT encounter-state-gated — a procedure that legitimately originated has its
 * own lifecycle independent of the encounter's later state (the ADR-135
 * post-origination principle).
 *
 * Per-subject command (`subjectId() = $procedureId`): the bus loads the
 * procedure and calls `ProcedurePolicy::authorize` (`clinical.procedures.request`
 * at the procedure's own facility — the same key as `RequestProcedure`) before
 * the handler runs.
 */
final readonly class AuthorizeProcedure implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $procedureId,
        public string $actorId,
    ) {
        if (! Str::isUuid($procedureId)) {
            throw new InvalidArgumentException('AuthorizeProcedure::$procedureId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AuthorizeProcedure::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'authorize';
    }

    public function subjectId(): string
    {
        return $this->procedureId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procedures.authorized',
            actorId: $this->actorId,
            targetType: 'clinical_procedure',
            targetId: $this->procedureId,
        );
    }
}
