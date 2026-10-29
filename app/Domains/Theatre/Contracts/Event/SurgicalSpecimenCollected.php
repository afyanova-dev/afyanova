<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * A specimen was collected during a surgical case (17-theatre-domain.md §210;
 * ADR-164 D-E). PHI-safe: identifiers only (ADR-136 deny-list — description /
 * body site / laterality NEVER carried).
 */
final readonly class SurgicalSpecimenCollected
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $surgicalSpecimenId,
        public string $caseId,
        public string $patientId,
        public string $encounterId,
        public int $sequence,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
