<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\Event;

final readonly class TicketPlaced
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $entryId,
        public string $queueId,
        public string $encounterId,
        public string $patientId,
        public string $ticketNumber,
        public string $priority,
        public ?string $sourceOrderRef,
        public ?string $orderType,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
