<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * A surgical case was completed (17-theatre-domain.md §210; ADR-150 D-K).
 * Outcome/complications are row-only (ADR-136 deny-list), never carried
 * here. PHI-safe: identifiers and coded values only.
 */
final readonly class SurgicalCaseCompleted
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
