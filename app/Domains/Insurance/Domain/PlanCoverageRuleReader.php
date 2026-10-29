<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Contracts\Dto\PlanCoverageRuleSummary;

/**
 * Read-side port for plan coverage rules (IC §6.2; ADR-200 D-C / D-D). Returns
 * DTOs only.
 */
interface PlanCoverageRuleReader
{
    /**
     * The rule effective for a (plan, category) on a date — `effective_from <=
     * onDate` AND (`effective_to` is null OR `onDate < effective_to`). Null
     * when no rule is configured for that scope on that date.
     */
    public function effectiveRule(string $planId, string $serviceCategory, string $onDate): ?PlanCoverageRuleSummary;

    /**
     * The rules for a plan (all history), newest `effective_from` first,
     * optionally narrowed to one category.
     *
     * @return list<PlanCoverageRuleSummary>
     */
    public function forPlan(string $planId, ?string $serviceCategory): array;
}
