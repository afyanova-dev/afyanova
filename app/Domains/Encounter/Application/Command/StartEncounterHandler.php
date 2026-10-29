<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Service\EncounterLifecycle;

/**
 * Orchestrates `StartEncounter` (IC §5; ADR-121, ADR-130).
 *
 * Flow: load (RLS-scoped) → EncounterLifecycle validates `ARRIVED → ACTIVE`
 * (→ `IllegalEncounterTransition` 409 for any other status) → aggregate
 * transition (`status → ACTIVE`, `started_at → now()`, records `EncounterStarted`)
 * → versioned save (`ConcurrencyConflict` 409 on a stale version;
 * `EncounterStarted` to the outbox in the same transaction). The
 * `encounter.started` audit row is written by the bus `WriteAuditRow` step.
 *
 * NO unique-index handling: `ARRIVED` and `ACTIVE` are BOTH inside the
 * `enc_one_active_per_patient_facility` predicate and the index key
 * `(tenant_id, patient_id, facility_id)` is unchanged by the transition, so it
 * cannot be violated (unlike `RecordArrival`, where `PLANNED` is outside the
 * predicate and the entry is newly inserted).
 *
 * No `PatientApi` / capability check — the patient reference is immutable and
 * was established by `CreateEncounter` (ADR-130 E); capability gating is
 * `CreateEncounter` / `AdmitEncounter` only (ADR-120).
 *
 * `EncounterRepository` is injected both because the handler needs it and
 * because `AuthorizeCommand` (IC §5.1) reflects it to load the subject encounter
 * for the per-subject `start` policy check.
 */
final class StartEncounterHandler
{
    public function __construct(
        private readonly EncounterRepository $encounters,
        private readonly EncounterLifecycle $lifecycle,
    ) {}

    public function __invoke(StartEncounter $command): void
    {
        $encounter = $this->encounters->getById($command->encounterId); // RLS-scoped; NotFound if missing / cross-tenant

        $this->lifecycle->assertCanStart($encounter->status);

        $encounter->start();

        $this->encounters->save($encounter); // versioned UPDATE + EncounterStarted -> outbox (one txn)
    }
}
