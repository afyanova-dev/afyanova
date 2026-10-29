<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\CoverageDecision;
use App\Domains\Insurance\Domain\Service\CoverageEvaluator;

final class EvaluateCoverageHandler
{
    public function __construct(private readonly CoverageEvaluator $evaluator) {}

    public function __invoke(EvaluateCoverage $query): CoverageDecision
    {
        return $this->evaluator->evaluate(
            patientId: $query->patientId,
            planId: $query->planId,
            serviceCategory: $query->serviceCategory,
            chargeAmount: $query->chargeAmount,
            onDate: $query->onDate,
            facilityId: $query->facilityId,
        );
    }
}
