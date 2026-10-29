<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Contracts\Event;

use Carbon\CarbonImmutable;

final readonly class ReportRunFailed
{
    public function __construct(
        public string $tenantId,
        public string $reportRunId,
        public string $runNumber,
        public string $reportDefinitionCode,
        public string $failureReason,
        public CarbonImmutable $failedAt,
    ) {}
}
