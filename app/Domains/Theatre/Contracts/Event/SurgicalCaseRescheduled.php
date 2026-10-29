<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * A surgical case's scheduled time was changed (17-theatre-domain.md §103,
 * §210; ADR-150 D-K). The reason is row/audit-only (ADR-136 deny-list), never
 * carried here. PHI-safe: identifiers, coded values, timestamps only.
 */
final readonly class SurgicalCaseRescheduled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $caseId,
        public ?string $previousScheduledFor,
        public string $newScheduledFor,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
