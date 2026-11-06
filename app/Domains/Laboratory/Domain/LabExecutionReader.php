<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Contracts\Dto\LabExecutionQueueItemSummary;
use App\Domains\Laboratory\Contracts\Dto\LabExecutionSummary;

interface LabExecutionReader
{
    /** @return list<LabExecutionQueueItemSummary> */
    public function queueForFacility(string $facilityId): array;

    public function summary(string $executionId): LabExecutionSummary;
}
