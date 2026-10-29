<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * A surgical procedure was completed (17-theatre-domain.md §210; ADR-156 D-C).
 * PHI-safe: identifiers only (ADR-136 deny-list — descriptions / findings /
 * complications NEVER carried).
 */
final readonly class SurgicalProcedureCompleted
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $surgicalProcedureId,
        public string $caseId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
