<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Event;

/**
 * Emitted when a waitlist entry is cancelled.
 */
final readonly class WaitlistEntryCancelled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $waitlistEntryId,
        public ?string $reason,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
