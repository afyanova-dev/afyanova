<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Event;

/**
 * Emitted when a waitlist offer is declined.
 */
final readonly class WaitlistOfferDeclined
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $waitlistEntryId,
        public string $slotId,
        public ?string $reason,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
