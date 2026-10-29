<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record a patient's refusal of a procedure — `{AUTHORIZED, READY} →
 * DECLINED` (13-clinical-procedures-domain.md §37; ADR-146 D-F — `AUTHORIZED`
 * is a source state because `READY` is now definition-conditional; a patient
 * refusing a fast-path procedure must still be recorded as a refusal, not a
 * cancellation — 13 §4332). `$reason` is OPTIONAL (ADR-146 D-J) — lands in
 * `status_reason` on the row, never on the event or audit row.
 *
 * Per-subject command (`subjectId() = $procedureId`): the bus loads the
 * procedure and calls `ProcedurePolicy::decline` (`clinical.procedures.request`
 * at the procedure's own facility) before the handler runs.
 */
final readonly class DeclineProcedure implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $procedureId,
        public string $actorId,
        public ?string $reason = null,
    ) {
        if (! Str::isUuid($procedureId)) {
            throw new InvalidArgumentException('DeclineProcedure::$procedureId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('DeclineProcedure::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'decline';
    }

    public function subjectId(): string
    {
        return $this->procedureId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procedures.declined',
            actorId: $this->actorId,
            targetType: 'clinical_procedure',
            targetId: $this->procedureId,
        );
    }
}
