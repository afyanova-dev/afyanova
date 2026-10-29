<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Service\EncounterLifecycle;

/**
 * Executes `AbandonEncounter` (ADR-121:
 * `ARRIVED → LEFT_WITHOUT_BEING_SEEN | ACTIVE → ABANDONED`; ADR-130).
 *
 * Terminal transition. Non-idempotent. Moves the encounter aggregate to its
 * terminal abandonment status (`LEFT_WITHOUT_BEING_SEEN` if source was `ARRIVED`,
 * `ABANDONED` if source was `ACTIVE`). `EncounterAbandoned` is recorded on the
 * aggregate and flushed atomically into `shared_outbox_events` upon save. An
 * `encounter.abandoned` audit row is written by the bus `WriteAuditRow` step.
 *
 * Both terminal statuses exit the `enc_one_active_per_patient_facility` partial
 * unique index predicate (`WHERE status IN ('ARRIVED', 'ACTIVE')`), freeing the
 * active-attendance slot.
 *
 * `EncounterRepository` is injected both because the handler needs it and
 * because `AuthorizeCommand` reflects it to load the subject encounter for the
 * per-subject `abandon` policy check.
 */
final readonly class AbandonEncounterHandler
{
    public function __construct(
        private EncounterRepository $encounters,
        private EncounterLifecycle $lifecycle,
    ) {}

    public function __invoke(AbandonEncounter $command): void
    {
        $encounter = $this->encounters->getById($command->encounterId); // RLS-scoped; NotFound if missing / cross-tenant

        $targetStatus = $this->lifecycle->assertCanAbandon($encounter->status);

        $encounter->abandon($targetStatus);

        $this->encounters->save($encounter);
    }
}
