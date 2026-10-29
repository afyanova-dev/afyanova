<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\AssignmentSummary;
use App\Domains\HR\Domain\AssignmentReader;

final class GetActiveAssignmentsHandler
{
    public function __construct(private readonly AssignmentReader $reader) {}

    /**
     * @return list<AssignmentSummary>
     */
    public function __invoke(GetActiveAssignments $query): array
    {
        return $this->reader->getActiveAssignments($query->facilityId, $query->departmentId, $query->unitId);
    }
}
