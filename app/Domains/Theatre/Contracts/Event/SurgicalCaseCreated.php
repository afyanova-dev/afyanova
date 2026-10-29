<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * A surgical case was created (17-theatre-domain.md §210; ADR-150 D-K).
 *
 * PHI-safe: identifiers and coded values only (ADR-136 deny-list —
 * indication/findings/outcome/complications never carried).
 */
final readonly class SurgicalCaseCreated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $caseId,
        public string $patientId,
        public string $encounterId,
        public string $caseNumber,
        public string $priority,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
