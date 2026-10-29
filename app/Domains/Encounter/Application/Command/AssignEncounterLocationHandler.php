<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Service\EncounterLifecycle;

/**
 * Orchestrates `AssignEncounterLocation` (IC §5; ADR-121, ADR-130 B).
 *
 * Flow: load (RLS-scoped) → EncounterLifecycle validates the status
 * (`ARRIVED` or `ACTIVE` — else `IllegalEncounterTransition` 409) → aggregate
 * transition (patch `department_id` / `unit_id`; `status` unchanged; a resolved
 * no-op is rejected; records `EncounterLocationChanged`) → versioned save
 * (`ConcurrencyConflict` 409 on a stale version; `EncounterLocationChanged` to
 * the outbox in the same transaction). The `encounter.location_changed` audit
 * row is written by the bus `WriteAuditRow` step (`metadata = []` — ratified
 * D-5: the location ids are on the event).
 *
 * NO unique-index handling: `AssignEncounterLocation` keeps `status` in
 * `{ARRIVED, ACTIVE}` and does not touch the
 * `enc_one_active_per_patient_facility` index key
 * `(tenant_id, patient_id, facility_id)`, so the transition cannot violate it
 * (identical reasoning to `StartEncounter` / `AdmitEncounter` /
 * `ChangeEncounterDisposition`).
 *
 * No `PatientApi` / `OrganizationApi` / capability check — the patient
 * reference is immutable (ADR-130 E); the department / unit ids are stored
 * without structural validation (ADR-130 B — trusted server-side inputs, no
 * `OrganizationApi` method, a facility cross-check is a future refinement);
 * location is not capability-gated (ADR-120). Bed occupancy is Bed & Ward
 * Management's (ADR-107); operational location is Queueing & Patient Flow's
 * (doc 10 §6) — neither is touched here.
 *
 * `EncounterRepository` is injected both because the handler needs it and
 * because `AuthorizeCommand` (IC §5.1) reflects it to load the subject encounter
 * for the per-subject `assignLocation` policy check.
 */
final class AssignEncounterLocationHandler
{
    public function __construct(
        private readonly EncounterRepository $encounters,
        private readonly EncounterLifecycle $lifecycle,
    ) {}

    public function __invoke(AssignEncounterLocation $command): void
    {
        $encounter = $this->encounters->getById($command->encounterId); // RLS-scoped; NotFound if missing / cross-tenant

        $this->lifecycle->assertCanAssignLocation($encounter->status);

        $encounter->assignLocation($command->departmentId, $command->unitId);

        $this->encounters->save($encounter); // versioned UPDATE + EncounterLocationChanged -> outbox (one txn)
    }
}
