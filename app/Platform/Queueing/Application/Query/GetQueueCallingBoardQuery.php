<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Query;

final readonly class GetQueueCallingBoardQuery
{
    public function __construct(
        public string $facilityId,
        public ?string $queueId = null,
    ) {}
}
