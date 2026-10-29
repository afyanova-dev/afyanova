<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Derived leave balance projection (25-hr-and-workforce-domain.md §6, §7.9).
 */
final readonly class LeaveBalanceSummary
{
    public function __construct(
        public string $staffId,
        public string $leaveTypeId,
        public string $leaveTypeCode,
        public string $leaveTypeName,
        public bool $isPaid,
        public bool $allowNegative,
        public float $accrued,
        public float $taken,
        public float $adjusted,
        public float $balance,
        public float $pendingRequestedDays,
    ) {}
}
