<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Event;

/**
 * A follow-up was missed — the due window elapsed with no encounter
 * (26-care-coordination-domain.md §11; ADR-154 D-G).
 *
 * PHI-safe: identifiers only (ADR-136 deny-list).
 */
final readonly class FollowUpMissed
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $followUpId,
        public string $patientId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
