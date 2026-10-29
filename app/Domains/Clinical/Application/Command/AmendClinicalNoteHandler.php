<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalNoteRepository;
use App\Domains\Clinical\Domain\Service\ClinicalNoteLifecycle;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `AmendClinicalNote` (IC §5; ADR-134).
 *
 * Flow: load (RLS-scoped) → `ClinicalNoteLifecycle` validates `SIGNED → SIGNED`
 * (status not `SIGNED` → `IllegalClinicalNoteTransition` 409) → aggregate
 * appends one `clin_note_amendments` row + increments `amendment_count` (which
 * dirties the note row so its `version` bumps) + records `ClinicalNoteAmended`
 * → versioned save: the note `UPDATE`, then the append-only amendment `INSERT`,
 * then `ClinicalNoteAmended` to the outbox — one transaction
 * (`EloquentClinicalNoteRepository::save()`). `ConcurrencyConflict` (409) on a
 * stale version. The `clinical.note_amended` audit row is written by the bus
 * `WriteAuditRow` step (`metadata = []`, `reason = null` — the amendment's own
 * `reason` is clinical data on the amendment row).
 *
 * `occurred_at` = caller value or `now()`; `recorded_at` = `now()`. NO
 * `EncounterApi` / `PatientApi` call — amendment is not encounter-state-gated
 * (ADR-135). `ClinicalNoteRepository` is injected both because the handler
 * needs it and because `AuthorizeCommand` (IC §5.1) reflects it to load the
 * subject note for the per-subject `amend` policy check.
 */
final class AmendClinicalNoteHandler
{
    public function __construct(
        private readonly ClinicalNoteRepository $notes,
        private readonly ClinicalNoteLifecycle $lifecycle,
    ) {}

    public function __invoke(AmendClinicalNote $command): void
    {
        $note = $this->notes->getById($command->clinicalNoteId); // RLS-scoped; NotFound if missing / cross-tenant

        $this->lifecycle->assertCanAmend($note->status);

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;

        $note->amend(
            type: $command->amendmentTypeEnum(),
            body: $command->body,
            reason: $command->reason,
            authorUserId: $command->actorId,
            occurredAt: $occurredAt,
            recordedAt: $now,
        );

        $this->notes->save($note); // note UPDATE + amendment INSERT + ClinicalNoteAmended -> outbox (one txn)
    }
}
