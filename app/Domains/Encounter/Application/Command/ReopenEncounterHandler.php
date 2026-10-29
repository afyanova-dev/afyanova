<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Exception\ActiveEncounterExists;
use App\Domains\Encounter\Domain\Service\EncounterLifecycle;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Orchestrates `ReopenEncounter` (IC §5; ADR-121, ADR-130, Rulings E8-R1..E8-R8).
 *
 * Flow: load (RLS-scoped) → EncounterLifecycle validates `COMPLETED → ACTIVE`
 * (status not `COMPLETED` → `IllegalEncounterTransition` 409) → aggregate
 * transition (`status → ACTIVE`, records `EncounterReopened`, `completed_at`
 * preserved) → versioned save (`ConcurrencyConflict` 409 on a stale version;
 * `EncounterReopened` to the outbox in the same transaction). The
 * `encounter.reopened` audit row is written by the bus `WriteAuditRow` step
 * (`metadata = []`).
 *
 * `COMPLETED` is not in `enc_one_active_per_patient_facility` but `ACTIVE` is
 * (ADR-121, Ruling E8-R4); moving back into that set can violate the partial
 * unique index when another `ARRIVED`/`ACTIVE` encounter already exists for the
 * same (tenant, patient, facility) — that specific violation becomes
 * `ActiveEncounterExists` (IC §9.1); any other unique violation is re-thrown.
 *
 * `EncounterRepository` is injected both because the handler needs it and
 * because `AuthorizeCommand` (IC §5.1) reflects it to load the subject encounter
 * for the per-subject `reopen` policy check.
 */
final class ReopenEncounterHandler
{
    public function __construct(
        private readonly EncounterRepository $encounters,
        private readonly EncounterLifecycle $lifecycle,
    ) {}

    public function __invoke(ReopenEncounter $command): void
    {
        $encounter = $this->encounters->getById($command->encounterId); // RLS-scoped; NotFound if missing / cross-tenant

        $this->lifecycle->assertCanReopen($encounter->status);

        $encounter->reopen();

        try {
            $this->encounters->save($encounter); // versioned UPDATE + EncounterReopened -> outbox (one txn)
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
