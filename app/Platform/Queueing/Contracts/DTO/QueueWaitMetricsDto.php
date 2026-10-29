<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\DTO;

use Carbon\CarbonImmutable;

final readonly class QueueWaitMetricsDto
{
    public function __construct(
        public string $queueId,
        public string $queueCode,
        public int $averageWaitSeconds,
        public int $averageServiceSeconds,
        public int $activeTicketsCount,
        public int $completedTodayCount,
        public int $lwbsCount,
        public CarbonImmutable $asOf,
    ) {}
}
