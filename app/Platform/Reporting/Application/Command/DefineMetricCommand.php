<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Command;

use App\Platform\Reporting\Domain\ValueObject\ReportCategory;

final readonly class DefineMetricCommand
{
    public function __construct(
        public string $tenantId,
        public string $code,
        public string $name,
        public string $owningDomain,
        public ReportCategory $category,
        public ?string $description = null,
        public ?string $calculationLogic = null,
        public ?string $unitOfMeasure = null,
        public ?string $signedOffBy = null,
    ) {}
}
