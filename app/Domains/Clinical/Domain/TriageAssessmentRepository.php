<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the TriageAssessment aggregate (IC §9; ADR-145 D-N).
 * Implementation: `Infrastructure/Repository/EloquentTriageAssessmentRepository`,
 * bound in `ClinicalServiceProvider`. Its FQCN sits directly under `Domain/`
 * (not `Domain/Repository/`) so `App\Shared\Bus\Middleware\AuthorizeCommand`
 * (IC §5.1) can derive `App\Domains\Clinical\Domain\Model\TriageAssessment` from
 * it for the class-level `record` and per-subject `correct` policy checks.
 */
interface TriageAssessmentRepository extends Repository
{
    public function getById(string $id): TriageAssessment;

    public function getByIdForUpdate(string $id): TriageAssessment;

    /**
     * Persist a correction / re-triage (ADR-145 D-F): the NEW (superseding)
     * assessment is inserted, then the ORIGINAL row is stamped with its one-time
     * supersession metadata (a versioned UPDATE) — all in the bus transaction,
     * with the `TriageAssessmentCorrected` event drained to the outbox.
     */
    public function saveCorrection(TriageAssessment $original, TriageAssessment $correction): void;
}
