<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when an approved leave request is confirmed taken (25-hr-and-workforce-domain.md §6, §8).
 */
final readonly class LeaveTaken
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $leaveRequestId,
        public string $staffId,
        public string $startDate,
        public string $endDate,
        public float $days,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
