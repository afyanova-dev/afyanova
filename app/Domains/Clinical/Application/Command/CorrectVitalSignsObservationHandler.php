<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Domains\Clinical\Domain\VitalSignsObservationRepository;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `CorrectVitalSignsObservation` (IC §5; ADR-138 D7).
 *
 * Flow: load the original (RLS-scoped; `NotFound` if missing / cross-tenant) →
 * build the NEW (superseding) observation, copying the original's tenant /
 * facility / patient / encounter context and carrying `supersedes_observation_id`
 * + the mandatory correction `reason` (records `VitalSignsObservationCorrected`)
 * → stamp the original with its ONE-TIME `superseded_at` +
 * `superseded_by_observation_id` (`VitalSignsObservationAlreadySuperseded` 409
 * if a prior correction already superseded it) → persist both rows in one
 * transaction (`saveCorrection`: INSERT the new row, then the versioned UPDATE
 * of the original, then drain the event). `ConcurrencyConflict` (409) on a
 * stale original version.
 *
 * `occurred_at` = the caller value or, when absent, the original's clinical time
 * (a correction restates the same clinical moment); `recorded_at` = `now()`. The
 * `clinical.vitals.corrected` audit row is written by the bus `WriteAuditRow`
 * step (`metadata = []`, `reason = null` — the correction rationale is clinical
 * data on the new row, ADR-138 item C).
 *
 * NO `EncounterApi` / `PatientApi` call — a correction is not encounter-state-
 * gated (ADR-138; the C.1 `AmendClinicalNote` precedent).
 * `VitalSignsObservationRepository` is injected both because the handler needs
 * it and because `AuthorizeCommand` (IC §5.1) reflects it to load the subject
 * observation for the per-subject `correct` policy check.
 */
final class CorrectVitalSignsObservationHandler
{
    public function __construct(
        private readonly VitalSignsObservationRepository $observations,
    ) {}

    public function __invoke(CorrectVitalSignsObservation $command): void
    {
        $original = $this->observations->getById($command->supersedesObservationId); // RLS-scoped; NotFound if missing / cross-tenant

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null
            ? CarbonImmutable::parse($command->occurredAt)
            : $original->occurred_at;

        $correction = VitalSignsObservation::correcting(
            id: $command->newObservationId,
            original: $original,
            authorUserId: $command->actorId,
            authoredContext: $command->authoredContext,
            measurements: $command->measurements,
            reason: $command->reason,
            occurredAt: $occurredAt,
            recordedAt: $now,
        );

        $original->markSupersededBy((string) $correction->id); // one-time; 409 if already superseded

        $this->observations->saveCorrection($original, $correction);
    }
}
