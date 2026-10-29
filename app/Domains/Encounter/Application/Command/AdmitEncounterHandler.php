<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Exception\EncounterTypeNotAvailable;
use App\Domains\Encounter\Domain\Service\EncounterLifecycle;
use App\Shared\Capability\FacilityCapabilities;

/**
 * Orchestrates `AdmitEncounter` (IC §5; ADR-108, ADR-120, ADR-121, ADR-130).
 *
 * Flow: load (RLS-scoped) → EncounterLifecycle validates the transition
 * (status `ACTIVE`, type `OUTPATIENT`/`EMERGENCY` — else `IllegalEncounterTransition`
 * 409) → `INPATIENT_CARE` capability gate (ADR-120 — `EncounterTypeNotAvailable`
 * 409 when not ENABLED) → aggregate transition (`encounter_type → INPATIENT`,
 * `admitted_at → now()`, `disposition → ADMITTED`, `status` unchanged; records
 * `EncounterAdmitted`) → versioned save (`ConcurrencyConflict` 409 on a stale
 * version; `EncounterAdmitted` to the outbox in the same transaction). The
 * `encounter.admitted` audit row is written by the bus `WriteAuditRow` step.
 *
 * The lifecycle guard runs BEFORE the capability check (ADR-130 D-3): a
 * non-`ACTIVE` / wrong-type encounter at a non-inpatient facility gets the
 * accurate lifecycle error, not a misleading capability error.
 *
 * NO unique-index handling: `AdmitEncounter` keeps `status = ACTIVE` and does
 * not change the `enc_one_active_per_patient_facility` index key
 * `(tenant_id, patient_id, facility_id)` — `encounter_type` is not in the index —
 * so the transition cannot violate it (identical reasoning to `StartEncounter`).
 *
 * No `PatientApi` — the patient reference is immutable and was validated by
 * `CreateEncounter` (ADR-130 E). Bed / ward is Bed & Ward Management's (ADR-107).
 *
 * `EncounterRepository` is injected both because the handler needs it and
 * because `AuthorizeCommand` (IC §5.1) reflects it to load the subject encounter
 * for the per-subject `admit` policy check.
 */
final class AdmitEncounterHandler
{
    public function __construct(
        private readonly EncounterRepository $encounters,
        private readonly EncounterLifecycle $lifecycle,
        private readonly FacilityCapabilities $capabilities,
    ) {}

    public function __invoke(AdmitEncounter $command): void
    {
        $encounter = $this->encounters->getById($command->encounterId); // RLS-scoped; NotFound if missing / cross-tenant

        $this->lifecycle->assertCanAdmit($encounter->status, $encounter->encounter_type);

        if (! $this->capabilities->has((string) $encounter->facility_id, 'INPATIENT_CARE')) {
            throw EncounterTypeNotAvailable::for(EncounterType::Inpatient, 'INPATIENT_CARE', (string) $encounter->facility_id);
        }

        $encounter->admit();

        $this->encounters->save($encounter); // versioned UPDATE + EncounterAdmitted -> outbox (one txn)
    }
}
