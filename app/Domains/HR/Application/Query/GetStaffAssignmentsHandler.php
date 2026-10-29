<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\AssignmentSummary;
use App\Domains\HR\Domain\AssignmentReader;

final class GetStaffAssignmentsHandler
{
    public function __construct(private readonly AssignmentReader $reader) {}

    /**
     * @return list<AssignmentSummary>
     */
    public function __invoke(GetStaffAssignments $query): array
    {
        return $this->reader->forStaff($query->staffId);
    }
}
