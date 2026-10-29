<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\Event;

final readonly class TicketNoResponse
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $entryId,
        public string $queueId,
        public string $encounterId,
        public string $ticketNumber,
        public int $callAttempts,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
