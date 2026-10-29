<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Stop a procedure after execution started — `IN_PROGRESS → ABORTED`
 * (13-clinical-procedures-domain.md §35; ADR-146 D-F / D-J). Distinct from
 * `CancelProcedure` (before starting). `$reason` is MANDATORY — a stopped
 * procedure is a safety / governance event — and lands in `status_reason` on
 * the row, never on the event or audit row.
 *
 * Per-subject command (`subjectId() = $procedureId`): the bus loads the
 * procedure and calls `ProcedurePolicy::abort` (`clinical.procedures.perform`
 * at the procedure's own facility) before the handler runs.
 */
final readonly class AbortProcedure implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $procedureId,
        public string $actorId,
        public string $reason,
    ) {
        if (! Str::isUuid($procedureId)) {
            throw new InvalidArgumentException('AbortProcedure::$procedureId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AbortProcedure::$actorId must be a UUID (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('AbortProcedure::$reason is required.');
        }
    }

    public function policyAbility(): string
    {
        return 'abort';
    }

    public function subjectId(): string
    {
        return $this->procedureId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procedures.aborted',
            actorId: $this->actorId,
            targetType: 'clinical_procedure',
            targetId: $this->procedureId,
        );
    }
}
