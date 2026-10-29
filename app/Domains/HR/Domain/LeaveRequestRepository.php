<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Domain\Model\LeaveRequest;
use App\Shared\Domain\Repository;
use Carbon\CarbonImmutable;

/**
 * Write-side repository port for LeaveRequest aggregate root (25-hr-and-workforce-domain.md §6, §7.12).
 */
interface LeaveRequestRepository extends Repository
{
    public function getById(string $id): LeaveRequest;

    public function getByIdForUpdate(string $id): LeaveRequest;

    public function hasOverlapping(
        string $staffId,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        ?string $excludeRequestId = null,
    ): bool;

    public function pendingDaysForStaffAndType(string $staffId, string $leaveTypeId): float;
}
