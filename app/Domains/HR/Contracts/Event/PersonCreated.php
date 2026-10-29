<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when a new Person record is created (25-hr-and-workforce-domain.md §11; ADR-206).
 */
final readonly class PersonCreated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $personId,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
