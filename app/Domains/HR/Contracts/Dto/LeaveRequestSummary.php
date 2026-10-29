<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Leave request summary (25-hr-and-workforce-domain.md §6, §8).
 */
final readonly class LeaveRequestSummary
{
    public function __construct(
        public string $leaveRequestId,
        public string $tenantId,
        public string $facilityId,
        public string $staffId,
        public ?string $staffNumber,
        public ?string $staffName,
        public string $leaveTypeId,
        public ?string $leaveTypeCode,
        public ?string $leaveTypeName,
        public string $startDate,
        public string $endDate,
        public float $days,
        public string $status,
        public ?string $reason,
        public ?string $reviewerId,
        public ?string $reviewedAt,
        public ?string $rejectionReason,
        public ?string $cancellationReason,
        public int $version,
    ) {}
}
