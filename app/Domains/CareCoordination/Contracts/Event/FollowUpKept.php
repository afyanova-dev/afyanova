<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Event;

/**
 * A follow-up was kept — it resulted in an encounter
 * (26-care-coordination-domain.md §11, Invariant 7.9; ADR-154 D-G).
 *
 * PHI-safe: identifiers only (ADR-136 deny-list).
 */
final readonly class FollowUpKept
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $followUpId,
        public string $patientId,
        public string $resultingEncounterId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
