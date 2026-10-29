<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Query;

use App\Platform\Reporting\Domain\Model\ReportDefinition;
use Illuminate\Database\Eloquent\Collection;

final class ListReportDefinitionsHandler
{
    /**
     * @return Collection<int, ReportDefinition>
     */
    public function __invoke(ListReportDefinitionsQuery $query): Collection
    {
        return ReportDefinition::query()
            ->where('tenant_id', $query->tenantId)
            ->when($query->category !== null, fn ($q) => $q->where('category', $query->category))
            ->orderBy('code')
            ->get();
    }
}
