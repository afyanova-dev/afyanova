<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Exception\ActiveEncounterExists;
use App\Domains\Encounter\Domain\Service\EncounterLifecycle;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Orchestrates `RecordArrival` (IC §5; ADR-121, ADR-130).
 *
 * Flow: load (RLS-scoped) → EncounterLifecycle validates `PLANNED → ARRIVED`
 * (→ `IllegalEncounterTransition` 409 for any other status) → aggregate
 * transition (`status → ARRIVED`, `arrived_at → now()`, records `EncounterArrived`)
 * → versioned save (`ConcurrencyConflict` 409 on a stale version;
 * `EncounterArrived` to the outbox in the same transaction). The
 * `encounter.arrived` audit row is written by the bus `WriteAuditRow` step.
 *
 * `PLANNED` is not in `enc_one_active_per_patient_facility` but `ARRIVED` is
 * (ADR-121); moving into that set can violate the partial unique index when a
 * walk-in `ARRIVED`/`ACTIVE` encounter already exists for the same
 * (tenant, patient, facility) — that specific violation becomes
 * `ActiveEncounterExists` (IC §9.1); any other unique violation is re-thrown.
 *
 * No `PatientApi` / capability check — the patient reference is immutable and
 * was established by `CreateEncounter` (ADR-130 E).
 *
 * `EncounterRepository` is injected both because the handler needs it and
 * because `AuthorizeCommand` (IC §5.1) reflects it to load the subject encounter
 * for the per-subject `recordArrival` policy check.
 */
final class RecordArrivalHandler
{
    public function __construct(
        private readonly EncounterRepository $encounters,
        private readonly EncounterLifecycle $lifecycle,
    ) {}

    public function __invoke(RecordArrival $command): void
    {
        $encounter = $this->encounters->getById($command->encounterId); // RLS-scoped; NotFound if missing / cross-tenant

        $this->lifecycle->assertCanRecordArrival($encounter->status);

        $encounter->recordArrival();

        try {
            $this->encounters->save($encounter); // versioned UPDATE + EncounterArrived -> outbox (one txn)
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'enc_one_active_per_patient_facility')) {
                throw ActiveEncounterExists::forPatientAtFacility(
                    (string) $encounter->patient_id,
                    (string) $encounter->facility_id,
                );
            }

            throw $e;
        }
    }
}
