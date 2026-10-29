<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when a leave request is cancelled (25-hr-and-workforce-domain.md §6, §8).
 */
final readonly class LeaveCancelled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $leaveRequestId,
        public string $staffId,
        public string $cancellationReason,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
