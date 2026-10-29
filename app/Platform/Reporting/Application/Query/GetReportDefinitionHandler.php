<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Query;

use App\Platform\Reporting\Domain\Model\ReportDefinition;

final class GetReportDefinitionHandler
{
    public function __invoke(GetReportDefinitionQuery $query): ?ReportDefinition
    {
        return ReportDefinition::query()
            ->where('tenant_id', $query->tenantId)
            ->where('code', $query->code)
            ->first();
    }
}
