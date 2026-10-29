<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the ClinicalNote aggregate (IMPLEMENTATION-CONTRACTS.md
 * §9). Implementation: `Infrastructure/Repository/EloquentClinicalNoteRepository`,
 * bound in `ClinicalServiceProvider`. Its FQCN sits directly under `Domain/`
 * (not `Domain/Repository/`) so `App\Shared\Bus\Middleware\AuthorizeCommand`
 * (IC §5.1) can derive `App\Domains\Clinical\Domain\Model\ClinicalNote` from it
 * for the class-level `create` policy check.
 *
 * Return types narrow the base `Repository` (which returns `AggregateRoot`) to
 * `ClinicalNote` — a covariant refinement.
 */
interface ClinicalNoteRepository extends Repository
{
    public function getById(string $id): ClinicalNote;

    public function getByIdForUpdate(string $id): ClinicalNote;
}
