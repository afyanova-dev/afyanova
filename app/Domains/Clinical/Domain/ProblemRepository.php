<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Domain\Model\Problem;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Problem aggregate (IC §9; ADR-140). Implementation:
 * `Infrastructure/Repository/EloquentProblemRepository`, bound in
 * `ClinicalServiceProvider`. Its FQCN sits directly under `Domain/` (not
 * `Domain/Repository/`) so `App\Shared\Bus\Middleware\AuthorizeCommand`
 * (IC §5.1) can derive `App\Domains\Clinical\Domain\Model\Problem` from it for
 * the class-level `record` (AddProblem) and per-subject `update` / `resolve`
 * (UpdateProblem / ResolveProblem) policy checks.
 *
 * `save()` (inherited signature) persists the versioned `clin_problems` change,
 * then any pending append-only `clin_problem_history` rows, then drains the
 * released domain events to the outbox — all in the bus transaction (the
 * `EloquentClinicalNoteRepository` precedent). There is NO `saveCorrection`
 * method — C.4 has no superseding-record model (ADR-140 D-D).
 */
interface ProblemRepository extends Repository
{
    public function getById(string $id): Problem;

    public function getByIdForUpdate(string $id): Problem;
}
