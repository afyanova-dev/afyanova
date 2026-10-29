<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Query;

final readonly class GetFacilityWaitingEntriesQuery
{
    public function __construct(
        public string $facilityId,
        public int $limit = 200,
    ) {}
}
