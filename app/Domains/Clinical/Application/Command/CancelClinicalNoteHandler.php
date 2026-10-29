<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalNoteRepository;
use App\Domains\Clinical\Domain\Service\ClinicalNoteLifecycle;

/**
 * Orchestrates `CancelClinicalNote` (IC §5; ADR-134).
 *
 * Flow: load (RLS-scoped) → `ClinicalNoteLifecycle` validates `DRAFT → CANCELLED`
 * (status not `DRAFT` → `IllegalClinicalNoteTransition` 409) → aggregate
 * transition (`status → CANCELLED` only) → versioned save (`ConcurrencyConflict`
 * 409 on a stale version).
 *
 * NO domain event, NO audit row (the command is not `Auditable`), NO
 * `EncounterApi` / `PatientApi` call, NO encounter-state gate (ADR-134,
 * ADR-135). The versioned `UPDATE` still runs — `status` changes, so the row is
 * dirty and `version` bumps. `ClinicalNoteRepository` is injected both because
 * the handler needs it and because `AuthorizeCommand` (IC §5.1) reflects it to
 * load the subject note for the per-subject `cancel` policy check.
 */
final class CancelClinicalNoteHandler
{
    public function __construct(
        private readonly ClinicalNoteRepository $notes,
        private readonly ClinicalNoteLifecycle $lifecycle,
    ) {}

    public function __invoke(CancelClinicalNote $command): void
    {
        $note = $this->notes->getById($command->clinicalNoteId); // RLS-scoped; NotFound if missing / cross-tenant

        $this->lifecycle->assertCanCancel($note->status);

        $note->cancel();

        $this->notes->save($note); // versioned UPDATE; no event, no audit
    }
}
