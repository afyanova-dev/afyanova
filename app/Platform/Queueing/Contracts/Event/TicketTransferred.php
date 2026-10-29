<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\Event;

final readonly class TicketTransferred
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $sourceEntryId,
        public string $newEntryId,
        public string $sourceQueueId,
        public string $destinationQueueId,
        public string $encounterId,
        public string $ticketNumber,
        public ?string $reason,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
