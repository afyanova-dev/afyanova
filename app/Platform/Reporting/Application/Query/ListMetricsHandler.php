<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Query;

use App\Platform\Reporting\Domain\Model\MetricDefinition;
use Illuminate\Database\Eloquent\Collection;

final class ListMetricsHandler
{
    /**
     * @return Collection<int, MetricDefinition>
     */
    public function __invoke(ListMetricsQuery $query): Collection
    {
        return MetricDefinition::query()
            ->where('tenant_id', $query->tenantId)
            ->when($query->owningDomain !== null, fn ($q) => $q->where('owning_domain', $query->owningDomain))
            ->when($query->category !== null, fn ($q) => $q->where('category', $query->category))
            ->orderBy('code')
            ->orderByDesc('version')
            ->get();
    }
}
