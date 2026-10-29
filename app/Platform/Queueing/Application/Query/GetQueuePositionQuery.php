<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Query;

final readonly class GetQueuePositionQuery
{
    public function __construct(
        public string $entryId,
    ) {}
}
