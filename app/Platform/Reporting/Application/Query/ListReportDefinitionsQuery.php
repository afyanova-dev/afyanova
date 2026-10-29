<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Query;

use App\Platform\Reporting\Domain\ValueObject\ReportCategory;

final readonly class ListReportDefinitionsQuery
{
    public function __construct(
        public string $tenantId,
        public ?ReportCategory $category = null,
    ) {}
}
