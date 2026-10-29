<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Query;

use App\Platform\Reporting\Domain\Model\ReportRun;

final class GetReportRunHandler
{
    public function __invoke(GetReportRunQuery $query): ?ReportRun
    {
        return ReportRun::query()
            ->where('tenant_id', $query->tenantId)
            ->where(function ($q) use ($query): void {
                $q->where('id', $query->runIdOrNumber)
                    ->orWhere('run_number', $query->runIdOrNumber);
            })
            ->first();
    }
}
