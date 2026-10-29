<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\PayerPlanSummary;
use App\Domains\Insurance\Domain\PlanReader;

final class SearchPayerPlansHandler
{
    public function __construct(private readonly PlanReader $reader) {}

    /**
     * @return list<PayerPlanSummary>
     */
    public function __invoke(SearchPayerPlans $query): array
    {
        return $this->reader->search($query->payerId, $query->status);
    }
}
