<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Reader;

use App\Domains\Insurance\Contracts\Dto\PlanCoverageRuleSummary;
use App\Domains\Insurance\Domain\Model\PlanCoverageRule;
use App\Domains\Insurance\Domain\Money;
use App\Domains\Insurance\Domain\PlanCoverageRuleReader;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Eloquent implementation of {@see PlanCoverageRuleReader} (IC §6.2; ADR-200
 * D-C / D-D).
 */
final class EloquentPlanCoverageRuleReader implements PlanCoverageRuleReader
{
    public function effectiveRule(string $planId, string $serviceCategory, string $onDate): ?PlanCoverageRuleSummary
    {
        $rule = PlanCoverageRule::query()
            ->where('plan_id', $planId)
            ->where('service_category', $serviceCategory)
            ->where('effective_from', '<=', $onDate)
            ->where(function (Builder $q) use ($onDate): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $onDate);
            })
            ->orderByDesc('effective_from')
            ->first();

        return $rule !== null ? $this->toSummary($rule) : null;
    }

    public function forPlan(string $planId, ?string $serviceCategory): array
    {
        $query = PlanCoverageRule::query()->where('plan_id', $planId);

        if ($serviceCategory !== null) {
            $query->where('service_category', strtoupper(trim($serviceCategory)));
        }

        return array_values(
            $query->orderByDesc('effective_from')->orderBy('id')->get()
                ->map(fn (PlanCoverageRule $r): PlanCoverageRuleSummary => $this->toSummary($r))
                ->all(),
        );
    }

    private function toSummary(PlanCoverageRule $r): PlanCoverageRuleSummary
    {
        return new PlanCoverageRuleSummary(
            ruleId: (string) $r->id,
            planId: (string) $r->plan_id,
            serviceCategory: (string) $r->service_category,
            coverageStatus: (string) $r->coverage_status,
            coveragePercent: $r->coverage_percent,
            coPayAmount: $r->co_pay_amount !== null ? Money::of((string) $r->co_pay_amount) : null,
            coPayCurrency: (string) $r->co_pay_currency,
            fixedBenefitAmount: $r->fixed_benefit_amount !== null ? Money::of((string) $r->fixed_benefit_amount) : null,
            authorizationRequired: (bool) $r->authorization_required,
            effectiveFrom: $r->effective_from->toDateString(),
            effectiveTo: $r->effective_to?->toDateString(),
            status: (string) $r->status,
            recordedAt: $r->recorded_at->toIso8601String(),
        );
    }
}
