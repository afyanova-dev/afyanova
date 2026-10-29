<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\Exception\IllegalCoverageRuleTransition;
use App\Domains\Insurance\Domain\PlanCoverageRuleRepository;

/**
 * Orchestrates `EndPlanCoverageRule` (ADR-200 D-B) — the `EndTariffHandler`
 * shape. Load the rule (per-subject) → require it OPEN + `effectiveTo` strictly
 * after its `effective_from` → `end()` → save.
 */
final class EndPlanCoverageRuleHandler
{
    public function __construct(private readonly PlanCoverageRuleRepository $rules) {}

    public function __invoke(EndPlanCoverageRule $command): void
    {
        $rule = $this->rules->getByIdForUpdate($command->ruleId);

        if (! $rule->isOpen()) {
            throw IllegalCoverageRuleTransition::noOpenRow((string) $rule->plan_id, (string) $rule->service_category);
        }

        if (! $command->effectiveTo->gt($rule->effective_from)) {
            throw IllegalCoverageRuleTransition::endDateNotAfterStart(
                $command->effectiveTo->toDateString(),
                $rule->effective_from->toDateString(),
            );
        }

        $rule->end($command->effectiveTo);

        $this->rules->save($rule);
    }
}
