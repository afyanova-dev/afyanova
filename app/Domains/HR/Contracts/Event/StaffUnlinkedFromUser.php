<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when staff is unlinked from an Identity User (25-hr-and-workforce-domain.md §11; ADR-206).
 */
final readonly class StaffUnlinkedFromUser
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $linkId,
        public string $staffId,
        public string $userId,
        public string $unlinkedAt,
        public string $unlinkedBy,
        public ?string $reason,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
