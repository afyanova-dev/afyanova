<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Contracts\Event;

use Carbon\CarbonImmutable;

final readonly class StatutoryReturnGenerated
{
    public function __construct(
        public string $tenantId,
        public string $returnId,
        public string $returnNumber,
        public string $framework,
        public string $periodKey,
        public string $reportingUnitCode,
        public int $lineCount,
        public CarbonImmutable $generatedAt,
    ) {}
}
