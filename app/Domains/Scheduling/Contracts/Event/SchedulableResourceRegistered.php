<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Event;

/**
 * Emitted when a schedulable resource is registered (ADR-111; 22-scheduling-and-appointments-domain.md §28).
 */
final readonly class SchedulableResourceRegistered
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $resourceId,
        public string $facilityId,
        public string $resourceType,
        public string $resourceRef,
        public string $name,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
