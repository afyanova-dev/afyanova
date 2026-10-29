<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Repository;

use App\Domains\HR\Domain\Exception\LeaveRequestNotFoundException;
use App\Domains\HR\Domain\LeaveRequestRepository;
use App\Domains\HR\Domain\Model\LeaveRequest;
use App\Domains\HR\Domain\ValueObject\LeaveRequestStatus;
use App\Shared\Domain\EloquentRepository;
use Carbon\CarbonImmutable;

/**
 * Eloquent implementation of LeaveRequestRepository (25-hr-and-workforce-domain.md §6, §7.12).
 */
final class EloquentLeaveRequestRepository extends EloquentRepository implements LeaveRequestRepository
{
    protected function model(): string
    {
        return LeaveRequest::class;
    }

    public function getById(string $id): LeaveRequest
    {
        return LeaveRequest::query()->whereKey($id)->first()
            ?? throw LeaveRequestNotFoundException::forId($id);
    }

    public function getByIdForUpdate(string $id): LeaveRequest
    {
        return LeaveRequest::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw LeaveRequestNotFoundException::forId($id);
    }

    public function hasOverlapping(
        string $staffId,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        ?string $excludeRequestId = null,
    ): bool {
        $startStr = $startDate->toDateString();
        $endStr = $endDate->toDateString();

        $query = LeaveRequest::query()
            ->where('staff_id', $staffId)
            ->whereIn('status', [
                LeaveRequestStatus::REQUESTED->value,
                LeaveRequestStatus::APPROVED->value,
                LeaveRequestStatus::TAKEN->value,
            ])
            ->where('start_date', '<=', $endStr)
            ->where('end_date', '>=', $startStr);

        if ($excludeRequestId !== null) {
            $query->where('id', '!=', $excludeRequestId);
        }

        return $query->exists();
    }

    public function pendingDaysForStaffAndType(string $staffId, string $leaveTypeId): float
    {
        return (float) LeaveRequest::query()
            ->where('staff_id', $staffId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('status', LeaveRequestStatus::REQUESTED->value)
            ->sum('days');
    }
}
