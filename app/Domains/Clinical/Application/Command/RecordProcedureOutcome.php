<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Set / revise a procedure's outcome — legal ONLY while `IN_PROGRESS`
 * (13-clinical-procedures-domain.md §38, §85; ADR-146 D-I / S3 / O-2). It keys
 * off status, not off whether `READY` occurred — a fast-path procedure can
 * still set the outcome before `CompleteProcedure`. ANY post-`COMPLETED`
 * outcome change goes through `AmendProcedure` instead (the `amend` key) — a
 * completed record is corrected only under the privileged key, never via this
 * `perform`-key command.
 *
 * `$outcome` is OPEN coded (the "no terminology engine" discipline); `null`
 * clears it. `$outcomeDetail` is free text — never in an event / audit row.
 *
 * Per-subject command (`subjectId() = $procedureId`): the bus loads the
 * procedure and calls `ProcedurePolicy::recordOutcome`
 * (`clinical.procedures.perform` at the procedure's own facility) before the
 * handler runs.
 */
final readonly class RecordProcedureOutcome implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $procedureId,
        public string $actorId,
        public ?string $outcome = null,
        public ?string $outcomeDetail = null,
    ) {
        if (! Str::isUuid($procedureId)) {
            throw new InvalidArgumentException('RecordProcedureOutcome::$procedureId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RecordProcedureOutcome::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'recordOutcome';
    }

    public function subjectId(): string
    {
        return $this->procedureId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procedures.outcome_recorded',
            actorId: $this->actorId,
            targetType: 'clinical_procedure',
            targetId: $this->procedureId,
        );
    }
}
