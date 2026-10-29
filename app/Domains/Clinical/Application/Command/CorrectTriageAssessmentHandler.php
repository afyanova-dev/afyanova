<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\Exception\TriageAssessmentContextMismatch;
use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Domains\Clinical\Domain\TriageAssessmentRepository;
use App\Domains\Clinical\Domain\VitalSignsObservationReader;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `CorrectTriageAssessment` (IC §5; ADR-145 D-F).
 *
 * Flow: load the original (RLS-scoped; `NotFound` if missing / cross-tenant) ->
 * revalidate the optional `sourceVitalsObservationId` (read-only via the C.2
 * reader) against the ORIGINAL's encounter -> build the NEW (superseding)
 * assessment, copying the original's tenant / facility / patient / encounter
 * context and carrying `supersedes_triage_assessment_id` + the mandatory
 * `reason` (records `TriageAssessmentCorrected`) -> stamp the original with its
 * ONE-TIME `superseded_at` + `superseded_by_triage_assessment_id`
 * (`TriageAssessmentAlreadySuperseded` 409 if a prior correction already
 * superseded it) -> persist both rows in one transaction (`saveCorrection`:
 * INSERT the new row, then the versioned UPDATE of the original, then drain the
 * event). `ConcurrencyConflict` (409) on a stale original version.
 *
 * `occurred_at` = the caller value or, when absent, the original's clinical time
 * (a re-triage / correction restates the same triage event); `recorded_at` =
 * `now()`. The `clinical.triage.corrected` audit row is written by the bus
 * `WriteAuditRow` step (`metadata = []`, `reason = null` — the rationale is
 * clinical data on the new row, ADR-145 D-K).
 *
 * NO `EncounterApi` / `PatientApi` call — a correction / re-triage is not
 * encounter-state-gated (ADR-145; the C.1 / C.2 / C.3 post-origination
 * precedent). `TriageAssessmentRepository` is injected both because the handler
 * needs it and because `AuthorizeCommand` (IC §5.1) reflects it to load the
 * subject assessment for the per-subject `correct` policy check.
 * `VitalSignsObservationReader` is an INTRA-domain read port (ADR-140 D-I).
 */
final class CorrectTriageAssessmentHandler
{
    public function __construct(
        private readonly TriageAssessmentRepository $assessments,
        private readonly VitalSignsObservationReader $vitals,
    ) {}

    public function __invoke(CorrectTriageAssessment $command): void
    {
        $original = $this->assessments->getById($command->supersedesTriageAssessmentId); // RLS-scoped; NotFound if missing / cross-tenant

        if ($command->sourceVitalsObservationId !== null) {
            $observation = $this->vitals->summary($command->sourceVitalsObservationId); // READ-ONLY; RLS-scoped; NotFound if missing / cross-tenant

            if ($observation->encounterId !== (string) $original->encounter_id) {
                throw TriageAssessmentContextMismatch::sourceVitals(
                    (string) $original->encounter_id,
                    $command->sourceVitalsObservationId,
                    $observation->encounterId,
                );
            }
        }

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null
            ? CarbonImmutable::parse($command->occurredAt)
            : $original->occurred_at;

        $correction = TriageAssessment::correcting(
            id: $command->newTriageAssessmentId,
            original: $original,
            authorUserId: $command->actorId,
            authoredContext: $command->authoredContext,
            chiefComplaint: $command->chiefComplaint,
            triagePriority: $command->triagePriority,
            acuity: $command->acuity,
            consciousness: $command->consciousness,
            sourceVitalsObservationId: $command->sourceVitalsObservationId,
            reason: $command->reason,
            occurredAt: $occurredAt,
            recordedAt: $now,
        );

        $original->markSupersededBy((string) $correction->id); // one-time; 409 if already superseded

        $this->assessments->saveCorrection($original, $correction);
    }
}
