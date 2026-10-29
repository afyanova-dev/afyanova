<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * A surgical case was cancelled (17-theatre-domain.md §210, §299
 * Invariant 15; ADR-150 D-K). The reason is row/audit-only (ADR-136
 * deny-list), never carried here. PHI-safe: identifiers only.
 */
final readonly class SurgicalCaseCancelled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $caseId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
