<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when two staff members swap shifts across published roster entries (25-hr-and-workforce-domain.md §8).
 */
final readonly class ShiftSwapped
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $entryAId,
        public string $staffAId,
        public string $entryBId,
        public string $staffBId,
        public string $swappedAt,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
