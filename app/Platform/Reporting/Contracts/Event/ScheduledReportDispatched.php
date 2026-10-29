<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Contracts\Event;

use Carbon\CarbonImmutable;

final readonly class ScheduledReportDispatched
{
    public function __construct(
        public string $tenantId,
        public string $reportRunId,
        public string $reportDefinitionCode,
        public int $recipientCount,
        public CarbonImmutable $dispatchedAt,
    ) {}
}
