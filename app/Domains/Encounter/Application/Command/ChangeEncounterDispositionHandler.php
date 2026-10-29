<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Service\EncounterLifecycle;

/**
 * Orchestrates `ChangeEncounterDisposition` (IC §5; ADR-121, ADR-130 A).
 *
 * Flow: load (RLS-scoped) → EncounterLifecycle validates the change (status
 * `ACTIVE`; requested disposition is one of the six settable values — else
 * `IllegalEncounterTransition` 409) → aggregate transition (`disposition` set;
 * `status` unchanged; a same-value request is rejected; records
 * `EncounterDispositionChanged`) → versioned save (`ConcurrencyConflict` 409 on
 * a stale version; `EncounterDispositionChanged` to the outbox in the same
 * transaction). The `encounter.disposition_changed` audit row is written by the
 * bus `WriteAuditRow` step (`metadata = []` — ADR-130 D-5 ratified: the coded
 * value is on the event and the action names the operation).
 *
 * NO unique-index handling: `ChangeEncounterDisposition` keeps `status = ACTIVE`
 * and does not touch the `enc_one_active_per_patient_facility` index key
 * `(tenant_id, patient_id, facility_id)`, so the transition cannot violate it
 * (identical reasoning to `StartEncounter` / `AdmitEncounter`).
 *
 * No `PatientApi` / capability check — the patient reference is immutable and
 * was established by `CreateEncounter` (ADR-130 E); disposition is not
 * capability-gated (ADR-120 gates `CreateEncounter` / `AdmitEncounter` only). A
 * `DECEASED` disposition records the ENCOUNTER outcome only — identity-level
 * death stays Patient's `MarkPatientDeceased` (ADR-130 A).
 *
 * `EncounterRepository` is injected both because the handler needs it and
 * because `AuthorizeCommand` (IC §5.1) reflects it to load the subject encounter
 * for the per-subject `changeDisposition` policy check.
 */
final class ChangeEncounterDispositionHandler
{
    public function __construct(
        private readonly EncounterRepository $encounters,
        private readonly EncounterLifecycle $lifecycle,
    ) {}

    public function __invoke(ChangeEncounterDisposition $command): void
    {
        $encounter = $this->encounters->getById($command->encounterId); // RLS-scoped; NotFound if missing / cross-tenant

        $this->lifecycle->assertCanChangeDisposition($encounter->status, $command->disposition);

        $encounter->changeDisposition($command->disposition);

        $this->encounters->save($encounter); // versioned UPDATE + EncounterDispositionChanged -> outbox (one txn)
    }
}
