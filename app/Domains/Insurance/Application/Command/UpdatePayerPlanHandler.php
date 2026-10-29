<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\PlanRepository;

/**
 * Orchestrates `UpdatePayerPlan` (ADR-198 D-H). `getByIdForUpdate` →
 * `rename()` + `reviseEffectivePeriod()` → save.
 */
final class UpdatePayerPlanHandler
{
    public function __construct(private readonly PlanRepository $plans) {}

    public function __invoke(UpdatePayerPlan $command): void
    {
        $plan = $this->plans->getByIdForUpdate($command->planId);

        $plan->rename($command->name);
        $plan->reviseEffectivePeriod($command->effectiveFrom, $command->effectiveTo);

        $this->plans->save($plan);
    }
}
