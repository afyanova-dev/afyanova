<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Event;

/**
 * Emitted when an available slot is offered to a waitlist candidate.
 */
final readonly class WaitlistSlotOffered
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $waitlistEntryId,
        public string $slotId,
        public string $offeredUntil,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
