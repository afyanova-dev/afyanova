<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use App\Domains\Scheduling\Contracts\Dto\SchedulableResourceSummary;
use App\Domains\Scheduling\Domain\SchedulableResourceReader;

final class GetSchedulableResourcesHandler
{
    public function __construct(
        private readonly SchedulableResourceReader $reader,
    ) {}

    /**
     * @return list<SchedulableResourceSummary>
     */
    public function __invoke(GetSchedulableResources $query): array
    {
        return $this->reader->listForFacility($query->facilityId, $query->resourceType);
    }
}
