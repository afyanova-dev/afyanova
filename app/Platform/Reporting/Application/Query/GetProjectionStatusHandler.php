<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Query;

use App\Platform\Reporting\Domain\Model\ProjectionCheckpoint;

final class GetProjectionStatusHandler
{
    public function __invoke(GetProjectionStatusQuery $query): ?ProjectionCheckpoint
    {
        return ProjectionCheckpoint::query()
            ->where('tenant_id', $query->tenantId)
            ->where('stream_name', $query->streamName)
            ->first();
    }
}
