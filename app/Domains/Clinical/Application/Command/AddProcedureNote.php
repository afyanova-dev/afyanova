<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Document a procedure with a `ClinicalNote` — `note_type = 'procedure_note'`
 * (13-clinical-procedures-domain.md §39–§40; ADR-146 D-L).
 *
 * `ClinicalNoteType` is already a fully-open shape-only string (ADR-134), so
 * this command makes NO change to the C.1 write path — NO `authored_context`
 * widening, NO ADR-134 / ADR-144 amendment, NO second authorization gate.
 * `AddProcedureNoteHandler` dispatches the EXISTING, UNCHANGED
 * `CreateClinicalNote` command on the bus (so the actor must ALSO hold
 * `clinical.notes.create` — a dual-permission requirement enforced
 * automatically by that dispatch, ADR-146 D-L R-5) and links the resulting
 * note to this procedure. The note therefore inherits the C.1 ADR-135
 * origination gate (encounter ∈ {ARRIVED, ACTIVE, COMPLETED}) even though
 * other post-origination procedure commands are not encounter-state-gated — an
 * accepted, explicit limitation.
 *
 * Per-subject command (`subjectId() = $procedureId`): the bus loads the
 * procedure and calls `ProcedurePolicy::addNote` (`clinical.procedures.perform`
 * at the procedure's own facility) before the handler runs.
 */
final readonly class AddProcedureNote implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $procedureId,
        public string $actorId,
        public string $body,
        public ?string $title = null,
        public string $authoredContext = ClinicalProcedure::AUTHORED_CONTEXT_CLINICIAN,
        public ?string $occurredAt = null,
        public ?string $reason = null,
    ) {
        if (! Str::isUuid($procedureId)) {
            throw new InvalidArgumentException('AddProcedureNote::$procedureId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AddProcedureNote::$actorId must be a UUID (IC §3).');
        }

        if (trim($body) === '') {
            throw new InvalidArgumentException('AddProcedureNote::$body must not be blank.');
        }

        if (! in_array($authoredContext, ClinicalProcedure::AUTHORED_CONTEXTS, true)) {
            throw new InvalidArgumentException('AddProcedureNote::$authoredContext must be CLINICIAN or NURSING (the ADR-144 mechanism).');
        }
    }

    public function policyAbility(): string
    {
        return 'addNote';
    }

    public function subjectId(): string
    {
        return $this->procedureId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procedures.note_added',
            actorId: $this->actorId,
            targetType: 'clinical_procedure',
            targetId: $this->procedureId,
        );
    }
}
