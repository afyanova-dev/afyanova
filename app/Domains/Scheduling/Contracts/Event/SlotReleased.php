<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Event;

/**
 * Emitted when a slot hold is released (22-scheduling-and-appointments-domain.md §45).
 */
final readonly class SlotReleased
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $slotId,
        public string $reason,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
