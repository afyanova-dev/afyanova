<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalNoteRepository;
use App\Domains\Clinical\Domain\Service\ClinicalNoteLifecycle;

/**
 * Orchestrates `SignClinicalNote` (IC §5; ADR-134).
 *
 * Flow: load (RLS-scoped) → `ClinicalNoteLifecycle` validates `DRAFT → SIGNED`
 * (status not `DRAFT` → `IllegalClinicalNoteTransition` 409) → aggregate
 * transition (`status → SIGNED`, `signed_at → now()` set once,
 * `signed_by_user_id` set; records `ClinicalNoteSigned`) → versioned save
 * (`ConcurrencyConflict` 409 on a stale version; `ClinicalNoteSigned` to the
 * outbox in the same transaction). The `clinical.note_signed` audit row is
 * written by the bus `WriteAuditRow` step (`metadata = []`).
 *
 * NO `EncounterApi` / `PatientApi` call — signing is not encounter-state-gated
 * (ADR-135) and the note already carries its immutable patient / encounter /
 * facility references. `ClinicalNoteRepository` is injected both because the
 * handler needs it and because `AuthorizeCommand` (IC §5.1) reflects it to load
 * the subject note for the per-subject `sign` policy check.
 */
final class SignClinicalNoteHandler
{
    public function __construct(
        private readonly ClinicalNoteRepository $notes,
        private readonly ClinicalNoteLifecycle $lifecycle,
    ) {}

    public function __invoke(SignClinicalNote $command): void
    {
        $note = $this->notes->getById($command->clinicalNoteId); // RLS-scoped; NotFound if missing / cross-tenant

        $this->lifecycle->assertCanSign($note->status);

        $note->sign($command->actorId);

        $this->notes->save($note); // versioned UPDATE + ClinicalNoteSigned -> outbox (one txn)
    }
}
