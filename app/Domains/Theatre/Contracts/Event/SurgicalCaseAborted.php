<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * A surgical case was aborted intraoperatively (17-theatre-domain.md §210,
 * §299 Invariant 16; ADR-150 D-K) — distinct from cancellation. The reason
 * is row/audit-only (ADR-136 deny-list), never carried here. PHI-safe:
 * identifiers only.
 */
final readonly class SurgicalCaseAborted
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
