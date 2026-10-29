<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the ClinicalProcedure aggregate (IC §9; ADR-146 D-U).
 * Implementation: `Infrastructure/Repository/EloquentClinicalProcedureRepository`,
 * bound in `ClinicalServiceProvider`. Its FQCN sits directly under `Domain/`
 * (not `Domain/Repository/`) so `App\Shared\Bus\Middleware\AuthorizeCommand`
 * (IC §5.1) can derive `App\Domains\Clinical\Domain\Model\ClinicalProcedure`
 * from it for the class-level `request` and per-subject policy checks.
 */
interface ClinicalProcedureRepository extends Repository
{
    public function getById(string $id): ClinicalProcedure;

    public function getByIdForUpdate(string $id): ClinicalProcedure;
}
