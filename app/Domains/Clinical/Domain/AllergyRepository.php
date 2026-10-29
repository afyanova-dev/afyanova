<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Domain\Model\Allergy;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Allergy aggregate (IC §9; ADR-141). Implementation:
 * `Infrastructure/Repository/EloquentAllergyRepository`, bound in
 * `ClinicalServiceProvider`. Its FQCN sits directly under `Domain/` (not
 * `Domain/Repository/`) so `App\Shared\Bus\Middleware\AuthorizeCommand`
 * (IC §5.1) can derive `App\Domains\Clinical\Domain\Model\Allergy` from it for
 * the class-level `record` (RecordAllergy) and per-subject `update` / `verify` /
 * `resolve` (UpdateAllergy / VerifyAllergy / ResolveAllergy) policy checks.
 *
 * `save()` persists the versioned `clin_allergies` change, then any pending
 * append-only `clin_allergy_history` rows, then drains the released domain
 * events to the outbox — all in the bus transaction (the
 * `EloquentProblemRepository` precedent). There is NO superseding-record model
 * (ADR-141 D-J).
 */
interface AllergyRepository extends Repository
{
    public function getById(string $id): Allergy;

    public function getByIdForUpdate(string $id): Allergy;
}
