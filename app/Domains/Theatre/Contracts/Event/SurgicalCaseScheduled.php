<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * A surgical case's scheduled time was set (17-theatre-domain.md §210;
 * ADR-150 D-K). PHI-safe: identifiers and coded values only.
 */
final readonly class SurgicalCaseScheduled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $caseId,
        public string $scheduledFor,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
