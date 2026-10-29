<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Query;

final readonly class GetDashboardSummaryQuery
{
    public function __construct(
        public string $tenantId,
        public string $facilityId,
        public ?string $date = null,
    ) {}
}
