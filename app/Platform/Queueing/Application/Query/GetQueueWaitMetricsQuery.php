<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Query;

final readonly class GetQueueWaitMetricsQuery
{
    public function __construct(
        public string $queueId,
    ) {}
}
