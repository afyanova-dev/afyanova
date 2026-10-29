<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when a new leave type policy is defined (25-hr-and-workforce-domain.md §6).
 */
final readonly class LeaveTypeDefined
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $leaveTypeId,
        public string $code,
        public string $name,
        public bool $isPaid,
        public float $accrualRatePerYear,
        public ?float $maxBalance,
        public bool $allowNegative,
        public ?float $carryOverLimit,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
