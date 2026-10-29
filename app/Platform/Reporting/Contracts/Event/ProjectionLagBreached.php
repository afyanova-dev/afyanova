<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Contracts\Event;

use Carbon\CarbonImmutable;

final readonly class ProjectionLagBreached
{
    public function __construct(
        public string $tenantId,
        public string $streamName,
        public int $currentLagSeconds,
        public int $thresholdSeconds,
        public CarbonImmutable $detectedAt,
    ) {}
}
