<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalProcedureRepository;
use App\Domains\Clinical\Domain\Service\ClinicalProcedureLifecycle;
use App\Shared\Bus\CommandBus;
use Illuminate\Support\Str;

/**
 * Orchestrates `AddProcedureNote` (IC §5; ADR-146 D-L).
 *
 * Flow: load the procedure (RLS-scoped) → `ClinicalProcedureLifecycle`
 * validates the procedure is in `{IN_PROGRESS, COMPLETED, ABORTED}` (else
 * `IllegalProcedureTransition` 409) → DISPATCH `CreateClinicalNote` on the
 * `CommandBus` (`noteType: 'procedure_note'`, the caller's `authoredContext`,
 * the procedure's own `patientId` / `encounterId`) → link the resulting
 * `clinicalNoteId` onto this procedure (no domain event — the C.1
 * `ClinicalNoteCreated` event already fired on the note's own aggregate) →
 * versioned save.
 *
 * The nested `CommandBus::dispatch()` runs inside the SAME database
 * transaction as this command: `Tenancy::run()` opens a Postgres SAVEPOINT
 * when already inside a transaction (Laravel's nested `DB::transaction()`
 * semantics), so the note INSERT and the procedure's `clinical_note_id`
 * UPDATE commit or roll back together. The inner dispatch runs its own
 * `ClinicalNotePolicy::create` check — the actor must ALSO hold
 * `clinical.notes.create` — and writes its OWN `clinical.note_created` audit
 * row (a second, distinct audit fact from this command's `procedures.note_added`).
 */
final class AddProcedureNoteHandler
{
    public function __construct(
        private readonly ClinicalProcedureRepository $procedures,
        private readonly ClinicalProcedureLifecycle $lifecycle,
        private readonly CommandBus $bus,
    ) {}

    public function __invoke(AddProcedureNote $command): AddProcedureNoteResult
    {
        $procedure = $this->procedures->getById($command->procedureId);

        $this->lifecycle->assertCanAddNote($procedure->status);

        /** @var CreateClinicalNoteResult $result */
        $result = $this->bus->dispatch(new CreateClinicalNote(
            clinicalNoteId: (string) Str::uuid7(),
            actorId: $command->actorId,
            patientId: (string) $procedure->patient_id,
            encounterId: (string) $procedure->encounter_id,
            noteType: 'procedure_note',
            title: $command->title,
            body: $command->body,
            authoredContext: $command->authoredContext,
            occurredAt: $command->occurredAt,
            reason: $command->reason,
        ));

        $procedure->linkClinicalNote($result->clinicalNoteId);

        $this->procedures->save($procedure);

        return new AddProcedureNoteResult($result->clinicalNoteId);
    }
}
