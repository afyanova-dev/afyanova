<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when staff is linked to an Identity User (25-hr-and-workforce-domain.md §11; ADR-206).
 */
final readonly class StaffLinkedToUser
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $linkId,
        public string $staffId,
        public string $userId,
        public string $linkedAt,
        public string $linkedBy,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
