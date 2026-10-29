<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Domain\Model\Diagnosis;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Diagnosis aggregate (IC §9; ADR-139). Implementation:
 * `Infrastructure/Repository/EloquentDiagnosisRepository`, bound in
 * `ClinicalServiceProvider`. Its FQCN sits directly under `Domain/` (not
 * `Domain/Repository/`) so `App\Shared\Bus\Middleware\AuthorizeCommand`
 * (IC §5.1) can derive `App\Domains\Clinical\Domain\Model\Diagnosis` from it for
 * the class-level `record` and per-subject `correct` policy checks.
 */
interface DiagnosisRepository extends Repository
{
    public function getById(string $id): Diagnosis;

    public function getByIdForUpdate(string $id): Diagnosis;

    /**
     * Persist a correction (ADR-139 D4): the NEW (superseding) diagnosis is
     * inserted, then the ORIGINAL row is stamped with its one-time supersession
     * metadata (a versioned UPDATE) — all in the bus transaction, with the
     * `DiagnosisCorrected` event drained to the outbox.
     */
    public function saveCorrection(Diagnosis $original, Diagnosis $correction): void;
}
