<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Query;

final readonly class ListFacilityQueuesQuery
{
    public function __construct(
        public string $facilityId,
        public bool $onlyActive = true,
    ) {}
}
