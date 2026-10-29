<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\PayerPlanSummary;
use App\Domains\Insurance\Domain\PlanReader;

final class GetPayerPlanHandler
{
    public function __construct(private readonly PlanReader $reader) {}

    public function __invoke(GetPayerPlan $query): PayerPlanSummary
    {
        return $this->reader->summary($query->planId);
    }
}
