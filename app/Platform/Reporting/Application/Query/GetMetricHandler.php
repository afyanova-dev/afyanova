<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Query;

use App\Platform\Reporting\Domain\Model\MetricDefinition;

final class GetMetricHandler
{
    public function __invoke(GetMetricQuery $query): ?MetricDefinition
    {
        $q = MetricDefinition::query()
            ->where('tenant_id', $query->tenantId)
            ->where('code', $query->code);

        if ($query->version !== null) {
            $q->where('version', $query->version);
        } else {
            $q->orderByDesc('version');
        }

        return $q->first();
    }
}
