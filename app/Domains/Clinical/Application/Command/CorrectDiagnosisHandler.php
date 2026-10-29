<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\DiagnosisRepository;
use App\Domains\Clinical\Domain\Model\Diagnosis;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `CorrectDiagnosis` (IC §5; ADR-139 D4).
 *
 * Flow: load the original (RLS-scoped; `NotFound` if missing / cross-tenant) ->
 * build the NEW (superseding) diagnosis, copying the original's tenant /
 * facility / patient / encounter context and carrying `supersedes_diagnosis_id`
 * + the mandatory correction `reason` (records `DiagnosisCorrected`) -> stamp
 * the original with its ONE-TIME `superseded_at` + `superseded_by_diagnosis_id`
 * (`DiagnosisAlreadySuperseded` 409 if a prior correction already superseded it)
 * -> persist both rows in one transaction (`saveCorrection`: INSERT the new row,
 * then the versioned UPDATE of the original, then drain the event).
 * `ConcurrencyConflict` (409) on a stale original version.
 *
 * `occurred_at` = the caller value or, when absent, the original's clinical time
 * (a correction restates the same conclusion); `recorded_at` = `now()`. The
 * `clinical.diagnoses.corrected` audit row is written by the bus `WriteAuditRow`
 * step (`metadata = []`, `reason = null` — the correction rationale is clinical
 * data on the new row, ADR-139 D11).
 *
 * NO `EncounterApi` / `PatientApi` call — a correction is not encounter-state-
 * gated (ADR-139; the C.1 / C.2 post-origination precedent).
 * `DiagnosisRepository` is injected both because the handler needs it and
 * because `AuthorizeCommand` (IC §5.1) reflects it to load the subject
 * diagnosis for the per-subject `correct` policy check.
 */
final class CorrectDiagnosisHandler
{
    public function __construct(
        private readonly DiagnosisRepository $diagnoses,
    ) {}

    public function __invoke(CorrectDiagnosis $command): void
    {
        $original = $this->diagnoses->getById($command->supersedesDiagnosisId); // RLS-scoped; NotFound if missing / cross-tenant

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null
            ? CarbonImmutable::parse($command->occurredAt)
            : $original->occurred_at;

        $correction = Diagnosis::correcting(
            id: $command->newDiagnosisId,
            original: $original,
            authorUserId: $command->actorId,
            coding: $command->coding,
            description: $command->description,
            clinicalStatus: $command->clinicalStatus,
            reason: $command->reason,
            occurredAt: $occurredAt,
            recordedAt: $now,
        );

        $original->markSupersededBy((string) $correction->id); // one-time; 409 if already superseded

        $this->diagnoses->saveCorrection($original, $correction);
    }
}
