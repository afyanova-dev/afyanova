<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Contracts\Event;

use Carbon\CarbonImmutable;

final readonly class ReportRunCompleted
{
    public function __construct(
        public string $tenantId,
        public string $reportRunId,
        public string $runNumber,
        public string $reportDefinitionCode,
        public int $rowCount,
        public ?string $documentId,
        public CarbonImmutable $completedAt,
    ) {}
}
