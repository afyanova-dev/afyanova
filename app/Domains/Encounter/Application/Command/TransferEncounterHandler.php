<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Service\EncounterLifecycle;

/**
 * Orchestrates `TransferEncounter` (IC §5; ADR-121, ADR-130).
 *
 * Flow: load (RLS-scoped) → EncounterLifecycle validates `ACTIVE → TRANSFERRED`
 * (status not `ACTIVE` → `IllegalEncounterTransition` 409) → aggregate transition
 * (`status → TRANSFERRED`, `disposition → TRANSFERRED`; every other field
 * untouched; records `EncounterTransferred`) → versioned save
 * (`ConcurrencyConflict` 409 on a stale version; `EncounterTransferred` to the
 * outbox in the same transaction). The `encounter.transferred` audit row is
 * written by the bus `WriteAuditRow` step (`metadata = []`).
 *
 * NO unique-index handling: `ACTIVE → TRANSFERRED` REMOVES the row from the
 * `enc_one_active_per_patient_facility` partial index predicate
 * (`WHERE status IN ('ARRIVED','ACTIVE')`) — deleting a row from a partial
 * unique index cannot raise a uniqueness violation. Identical reasoning to
 * `CompleteEncounterHandler` (E.7) / `CancelEncounterHandler` (E.9); contrast
 * `ReopenEncounterHandler` (E.8), which re-enters the predicate and must catch.
 *
 * No `PatientApi` / capability / cross-domain call — the patient reference is
 * immutable (ADR-130 E); transfer is not capability-gated (ADR-120); and
 * "encounter completion does not automatically complete downstream domain
 * workflows" (Invariant 18; §136–§139). The receiving facility's encounter is a
 * separate `CreateEncounter` (§8, §91); bed release (reason `TRANSFER`),
 * referral and billing react to `EncounterTransferred` on their own schedule.
 *
 * `EncounterRepository` is injected both because the handler needs it and
 * because `AuthorizeCommand` (IC §5.1) reflects it to load the subject encounter
 * for the per-subject `transfer` policy check.
 */
final class TransferEncounterHandler
{
    public function __construct(
        private readonly EncounterRepository $encounters,
        private readonly EncounterLifecycle $lifecycle,
    ) {}

    public function __invoke(TransferEncounter $command): void
    {
        $encounter = $this->encounters->getById($command->encounterId); // RLS-scoped; NotFound if missing / cross-tenant

        $this->lifecycle->assertCanTransfer($encounter->status);

        $encounter->transfer();

        $this->encounters->save($encounter); // versioned UPDATE + EncounterTransferred -> outbox (one txn)
    }
}
