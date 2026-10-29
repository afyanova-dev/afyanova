<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain;

use App\Domains\Scheduling\Contracts\Dto\SchedulableResourceSummary;

/**
 * Read-side reader port for SchedulableResource.
 */
interface SchedulableResourceReader
{
    public function findById(string $id): ?SchedulableResourceSummary;

    /**
     * @return list<SchedulableResourceSummary>
     */
    public function listForFacility(string $facilityId, ?string $resourceType = null): array;
}
