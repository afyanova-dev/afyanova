<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Leave type configuration summary (25-hr-and-workforce-domain.md §6).
 */
final readonly class LeaveTypeSummary
{
    public function __construct(
        public string $leaveTypeId,
        public string $tenantId,
        public string $code,
        public string $name,
        public bool $isPaid,
        public float $accrualRatePerYear,
        public ?float $maxBalance,
        public bool $allowNegative,
        public ?float $carryOverLimit,
        public bool $isActive,
        public int $version,
    ) {}
}
