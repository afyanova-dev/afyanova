<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalPlanRepository;

final class CloseClinicalPlanHandler
{
    public function __construct(
        private readonly ClinicalPlanRepository $plans,
    ) {}

    public function __invoke(CloseClinicalPlan $command): void
    {
        $plan = $this->plans->getByIdForUpdate($command->clinicalPlanId);

        $plan->close($command->outcome, $command->reason);

        $this->plans->save($plan);
    }
}
