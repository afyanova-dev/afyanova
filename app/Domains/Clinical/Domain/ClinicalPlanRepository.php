<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Domain\Model\ClinicalPlan;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the ClinicalPlan aggregate (IC §9; ADR-143).
 * Implementation: `Infrastructure/Repository/EloquentClinicalPlanRepository`, bound
 * in `ClinicalServiceProvider`.
 */
interface ClinicalPlanRepository extends Repository
{
    public function getById(string $id): ClinicalPlan;

    public function getByIdForUpdate(string $id): ClinicalPlan;
}
