<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Contracts\Dto;

use Carbon\CarbonImmutable;

final readonly class ProjectionHealthDto
{
    public function __construct(
        public string $streamName,
        public string $status,
        public int $checkpointOffset,
        public ?CarbonImmutable $lastEventTimestamp,
        public ?CarbonImmutable $lastRebuiltAt,
        public string $referenceConfigVersion,
        public int $lagSeconds,
    ) {}
}
