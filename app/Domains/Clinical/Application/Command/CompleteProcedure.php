<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Complete a procedure's execution — `IN_PROGRESS → COMPLETED`
 * (13-clinical-procedures-domain.md §33, §38, §56; ADR-146 D-F). The terminal
 * business fact that fires `ProcedureCompleted` (the Billing trigger, 13 §56)
 * — gated by its OWN key (`clinical.procedures.complete`), separate from
 * `perform`, so an organisation may restrict "who declares this done and
 * billable" (ADR-146 D-O).
 *
 * `$outcome` / `$outcomeDetail` / `$complications` are OPTIONAL (ADR-146 D-I) —
 * `RecordProcedureOutcome` (pre-completion) or `AmendProcedure`
 * (post-completion) may set or revise them later.
 *
 * Per-subject command (`subjectId() = $procedureId`): the bus loads the
 * procedure and calls `ProcedurePolicy::complete`
 * (`clinical.procedures.complete` at the procedure's own facility) before the
 * handler runs.
 */
final readonly class CompleteProcedure implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $procedureId,
        public string $actorId,
        public ?string $outcome = null,
        public ?string $outcomeDetail = null,
        public ?string $complications = null,
    ) {
        if (! Str::isUuid($procedureId)) {
            throw new InvalidArgumentException('CompleteProcedure::$procedureId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CompleteProcedure::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'complete';
    }

    public function subjectId(): string
    {
        return $this->procedureId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procedures.completed',
            actorId: $this->actorId,
            targetType: 'clinical_procedure',
            targetId: $this->procedureId,
        );
    }
}
