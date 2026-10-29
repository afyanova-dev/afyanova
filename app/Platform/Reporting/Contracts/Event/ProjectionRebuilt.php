<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Contracts\Event;

use Carbon\CarbonImmutable;

final readonly class ProjectionRebuilt
{
    public function __construct(
        public string $tenantId,
        public string $streamName,
        public int $checkpointOffset,
        public int $eventsProcessed,
        public CarbonImmutable $rebuiltAt,
    ) {}
}
