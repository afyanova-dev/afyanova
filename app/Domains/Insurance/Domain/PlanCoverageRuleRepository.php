<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Domain\Model\PlanCoverageRule;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the plan-coverage-rule aggregate (IC §9; ADR-200 D-B).
 * The FIRST repository parameter of the coverage-rule commands — the bus
 * resolves `PlanCoverageRulePolicy` from it.
 */
interface PlanCoverageRuleRepository extends Repository
{
    public function getById(string $id): PlanCoverageRule;

    public function getByIdForUpdate(string $id): PlanCoverageRule;

    /**
     * The single open (CURRENT, `effective_to is null`) rule for a (plan,
     * category) scope, row-locked for the current transaction. Null when none.
     * The `bill_tariffs` `openRowForScopeForUpdate` precedent.
     */
    public function openRowForScopeForUpdate(string $planId, string $serviceCategory): ?PlanCoverageRule;
}
