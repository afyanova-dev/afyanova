<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when a suspended employment contract is reinstated to ACTIVE (25-hr-and-workforce-domain.md §11; ADR-206).
 */
final readonly class EmploymentReinstated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $employmentId,
        public string $staffId,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
