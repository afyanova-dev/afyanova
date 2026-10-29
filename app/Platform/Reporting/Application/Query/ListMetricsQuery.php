<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Query;

use App\Platform\Reporting\Domain\ValueObject\ReportCategory;

final readonly class ListMetricsQuery
{
    public function __construct(
        public string $tenantId,
        public ?string $owningDomain = null,
        public ?ReportCategory $category = null,
    ) {}
}
