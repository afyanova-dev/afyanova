<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\PlanCoverageRuleSummary;
use App\Domains\Insurance\Domain\PlanCoverageRuleReader;

final class GetPlanCoverageRulesHandler
{
    public function __construct(private readonly PlanCoverageRuleReader $reader) {}

    /**
     * @return list<PlanCoverageRuleSummary>
     */
    public function __invoke(GetPlanCoverageRules $query): array
    {
        return $this->reader->forPlan($query->planId, $query->serviceCategory);
    }
}
