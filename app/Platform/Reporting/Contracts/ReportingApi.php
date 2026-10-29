<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Contracts;

use App\Platform\Reporting\Contracts\Dto\DashboardKpisDto;
use App\Platform\Reporting\Contracts\Dto\ProjectionHealthDto;

interface ReportingApi
{
    public function getDashboardKpis(string $facilityId, ?string $date = null): DashboardKpisDto;

    public function getProjectionHealth(string $streamName): ?ProjectionHealthDto;

    public function isMetricActive(string $metricKey): bool;
}
