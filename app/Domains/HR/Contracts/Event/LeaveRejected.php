<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when a leave request is rejected (25-hr-and-workforce-domain.md §6, §8).
 */
final readonly class LeaveRejected
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $leaveRequestId,
        public string $staffId,
        public string $reviewerId,
        public string $rejectionReason,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
