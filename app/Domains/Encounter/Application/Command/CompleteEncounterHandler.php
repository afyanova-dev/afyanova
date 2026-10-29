<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Service\EncounterLifecycle;

/**
 * Orchestrates `CompleteEncounter` (IC §5; ADR-121, ADR-130).
 *
 * Flow: load (RLS-scoped) → EncounterLifecycle validates `ACTIVE → COMPLETED`
 * with a recorded disposition (status not `ACTIVE` → `IllegalEncounterTransition`
 * 409; `disposition === null` → `IllegalEncounterTransition::dispositionRequired()`
 * 409) → aggregate transition (`status → COMPLETED`, `completed_at → now()`;
 * `disposition` and every other field untouched; records `EncounterCompleted`)
 * → versioned save (`ConcurrencyConflict` 409 on a stale version;
 * `EncounterCompleted` to the outbox in the same transaction). The
 * `encounter.completed` audit row is written by the bus `WriteAuditRow` step
 * (`metadata = []` — R-5: the coded disposition is on the event).
 *
 * NO unique-index handling: `ACTIVE → COMPLETED` REMOVES the row from the
 * `enc_one_active_per_patient_facility` partial index predicate
 * (`WHERE status IN ('ARRIVED','ACTIVE')`) — deleting a row from a partial
 * unique index cannot raise a uniqueness violation (R-7). E.7 is the first
 * Encounter lifecycle command that exits the active-attendance predicate; E.2
 * enters it (needs the catch), E.3–E.6 stay inside it, E.7 exits it.
 *
 * No `PatientApi` / capability / cross-domain call — the patient reference is
 * immutable (ADR-130 E); completion is not capability-gated (ADR-120); and
 * "encounter completion does not automatically complete downstream domain
 * workflows" (Invariant 18; §136–§139). Bed release on discharge is Bed & Ward
 * Management's, off `EncounterCompleted` (ADR-107; §79).
 *
 * `EncounterRepository` is injected both because the handler needs it and
 * because `AuthorizeCommand` (IC §5.1) reflects it to load the subject encounter
 * for the per-subject `complete` policy check.
 */
final class CompleteEncounterHandler
{
    public function __construct(
        private readonly EncounterRepository $encounters,
        private readonly EncounterLifecycle $lifecycle,
    ) {}

    public function __invoke(CompleteEncounter $command): void
    {
        $encounter = $this->encounters->getById($command->encounterId); // RLS-scoped; NotFound if missing / cross-tenant

        $this->lifecycle->assertCanComplete($encounter->status, $encounter->disposition);

        $encounter->complete();

        $this->encounters->save($encounter); // versioned UPDATE + EncounterCompleted -> outbox (one txn)
    }
}
