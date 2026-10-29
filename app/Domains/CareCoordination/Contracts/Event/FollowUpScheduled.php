<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Event;

/**
 * A follow-up was scheduled (26-care-coordination-domain.md §11; ADR-154 D-G).
 *
 * PHI-safe: identifiers only (ADR-136 deny-list — reason / instructions never
 * carried).
 */
final readonly class FollowUpScheduled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $followUpId,
        public string $patientId,
        public string $sourceEncounterId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
