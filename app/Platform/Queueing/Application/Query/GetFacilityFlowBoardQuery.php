<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Query;

final readonly class GetFacilityFlowBoardQuery
{
    public function __construct(
        public string $facilityId,
    ) {}
}
