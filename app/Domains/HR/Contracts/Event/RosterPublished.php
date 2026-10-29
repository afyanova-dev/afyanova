<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when a batch of draft roster entries are published for a date range (25-hr-and-workforce-domain.md §7).
 */
final readonly class RosterPublished
{
    /**
     * @param  list<string>  $publishedEntryIds
     */
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $startDate,
        public string $endDate,
        public array $publishedEntryIds,
        public int $publishedCount,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
