<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\Model\Plan;
use App\Domains\Insurance\Domain\PlanRepository;

/**
 * Orchestrates `SetPayerPlanStatus` (ADR-198 D-H). `getByIdForUpdate` →
 * `activate()` / `deactivate()` → save.
 */
final class SetPayerPlanStatusHandler
{
    public function __construct(private readonly PlanRepository $plans) {}

    public function __invoke(SetPayerPlanStatus $command): void
    {
        $plan = $this->plans->getByIdForUpdate($command->planId);

        if ($command->status === Plan::STATUS_ACTIVE) {
            $plan->activate();
        } else {
            $plan->deactivate($command->reason);
        }

        $this->plans->save($plan);
    }
}
