<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Domain\Model\Plan;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the payer-plan aggregate (IC §9; ADR-198 D-H). The FIRST
 * repository parameter of the plan commands — the bus resolves `PlanPolicy`
 * from it.
 */
interface PlanRepository extends Repository
{
    public function getById(string $id): Plan;

    public function getByIdForUpdate(string $id): Plan;

    /** True if a plan with this code exists for the payer in the current tenant scope. */
    public function existsByCode(string $payerId, string $code): bool;
}
