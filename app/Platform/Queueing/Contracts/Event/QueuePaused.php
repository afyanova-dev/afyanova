<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\Event;

final readonly class QueuePaused
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $queueId,
        public string $queueCode,
        public string $reason,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
