<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when a leave request is approved (25-hr-and-workforce-domain.md §6, §8).
 */
final readonly class LeaveApproved
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $leaveRequestId,
        public string $facilityId,
        public string $staffId,
        public string $leaveTypeId,
        public string $startDate,
        public string $endDate,
        public float $days,
        public string $reviewerId,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
