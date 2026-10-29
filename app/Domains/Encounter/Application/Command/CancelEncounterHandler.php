<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Service\EncounterLifecycle;
use App\Shared\Domain\NotFound;

/**
 * Handler for `CancelEncounter` (ADR-121: `PLANNED or ARRIVED → CANCELLED`; ADR-130).
 *
 * Pipeline sequence:
 *   1. Resolve subject aggregate via repository (or NotFound).
 *   2. Bus executes `AuthorizesAgainst` → `EncounterPolicy::cancel` (dedicated
 *      `encounter.encounters.cancel` key, tenant boundary, facility scope).
 *   3. Enforce lifecycle transition via `EncounterLifecycle::assertCanCancel`
 *      (permits `PLANNED` and `ARRIVED` only; rejects `ACTIVE`, `COMPLETED`,
 *      `CANCELLED`, etc. with 409 `illegal_encounter_transition`).
 *   4. Apply aggregate mutation via `$encounter->cancel()`, which updates
 *      `status = CANCELLED` and records `EncounterCancelled`.
 *   5. Save aggregate via repository (optimistic concurrency version bump,
 *      outbox drained, audit row written atomically in the command transaction).
 *
 * Note on uniqueness: `CancelEncounter` either stays outside (`PLANNED`) or
 * exits (`ARRIVED`) the `enc_one_active_per_patient_facility` partial index
 * predicate, so it cannot cause a unique index collision.
 */
final readonly class CancelEncounterHandler
{
    public function __construct(
        private EncounterRepository $encounters,
        private EncounterLifecycle $lifecycle,
    ) {}

    public function __invoke(CancelEncounter $command): void
    {
        $encounter = $this->encounters->getById($command->encounterId);

        $this->lifecycle->assertCanCancel($encounter->status);

        $encounter->cancel();

        $this->encounters->save($encounter);
    }
}
