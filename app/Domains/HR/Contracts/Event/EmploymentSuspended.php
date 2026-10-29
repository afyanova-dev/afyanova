<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when an employment contract is suspended (25-hr-and-workforce-domain.md §11; ADR-206).
 */
final readonly class EmploymentSuspended
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $employmentId,
        public string $staffId,
        public ?string $reason,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
