<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when a roster entry is cancelled (25-hr-and-workforce-domain.md §7).
 */
final readonly class ShiftCancelled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $rosterEntryId,
        public string $staffId,
        public string $date,
        public string $cancellationReason,
        public string $cancelledAt,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
