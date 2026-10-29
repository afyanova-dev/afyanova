<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Mark an authorized procedure ready for execution — `AUTHORIZED → READY`
 * (13-clinical-procedures-domain.md §28; ADR-146 D-F). Legal ONLY when the
 * procedure's `ProcedureDefinition` requires a readiness check — otherwise
 * `StartProcedure` applies the direct `AUTHORIZED → IN_PROGRESS` path.
 *
 * Per-subject command (`subjectId() = $procedureId`): the bus loads the
 * procedure and calls `ProcedurePolicy::markReady`
 * (`clinical.procedures.request` at the procedure's own facility) before the
 * handler runs.
 */
final readonly class MarkProcedureReady implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $procedureId,
        public string $actorId,
    ) {
        if (! Str::isUuid($procedureId)) {
            throw new InvalidArgumentException('MarkProcedureReady::$procedureId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('MarkProcedureReady::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'markReady';
    }

    public function subjectId(): string
    {
        return $this->procedureId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procedures.ready',
            actorId: $this->actorId,
            targetType: 'clinical_procedure',
            targetId: $this->procedureId,
        );
    }
}
