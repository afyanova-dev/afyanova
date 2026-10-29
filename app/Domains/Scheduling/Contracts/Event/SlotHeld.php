<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Event;

/**
 * Emitted when a bookable slot is held (22-scheduling-and-appointments-domain.md §44).
 */
final readonly class SlotHeld
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $slotId,
        public string $heldBy,
        public string $heldUntil,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
