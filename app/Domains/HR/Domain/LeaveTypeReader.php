<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Contracts\Dto\LeaveTypeSummary;

/**
 * Read-side port for LeaveType queries (25-hr-and-workforce-domain.md §6, §10).
 */
interface LeaveTypeReader
{
    public function summary(string $leaveTypeId): LeaveTypeSummary;

    /**
     * @return list<LeaveTypeSummary>
     */
    public function listAll(bool $onlyActive = true): array;
}
