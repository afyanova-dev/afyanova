<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Repository;

use App\Domains\Insurance\Domain\Model\PlanCoverageRule;
use App\Domains\Insurance\Domain\PlanCoverageRuleRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see PlanCoverageRuleRepository} (IC §9; ADR-200
 * D-B).
 */
final class EloquentPlanCoverageRuleRepository extends EloquentRepository implements PlanCoverageRuleRepository
{
    protected function model(): string
    {
        return PlanCoverageRule::class;
    }

    public function getById(string $id): PlanCoverageRule
    {
        return PlanCoverageRule::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(PlanCoverageRule::class, $id);
    }

    public function getByIdForUpdate(string $id): PlanCoverageRule
    {
        return PlanCoverageRule::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(PlanCoverageRule::class, $id);
    }

    public function openRowForScopeForUpdate(string $planId, string $serviceCategory): ?PlanCoverageRule
    {
        return PlanCoverageRule::query()
            ->where('plan_id', $planId)
            ->where('service_category', $serviceCategory)
            ->whereNull('effective_to')
            ->lockForUpdate()
            ->first();
    }
}
