<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Cancel a procedure before execution — `{REQUESTED, AUTHORIZED, READY} →
 * CANCELLED` (13-clinical-procedures-domain.md §34; ADR-146 D-F). Distinct
 * from `AbortProcedure` (after starting) and `DeclineProcedure` (patient
 * refusal). `$reason` is OPTIONAL (ADR-146 D-J) — lands in `status_reason` on
 * the row, never on the event or audit row.
 *
 * Per-subject command (`subjectId() = $procedureId`): the bus loads the
 * procedure and calls `ProcedurePolicy::cancel` (`clinical.procedures.request`
 * at the procedure's own facility) before the handler runs.
 */
final readonly class CancelProcedure implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $procedureId,
        public string $actorId,
        public ?string $reason = null,
    ) {
        if (! Str::isUuid($procedureId)) {
            throw new InvalidArgumentException('CancelProcedure::$procedureId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CancelProcedure::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'cancel';
    }

    public function subjectId(): string
    {
        return $this->procedureId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procedures.cancelled',
            actorId: $this->actorId,
            targetType: 'clinical_procedure',
            targetId: $this->procedureId,
        );
    }
}
