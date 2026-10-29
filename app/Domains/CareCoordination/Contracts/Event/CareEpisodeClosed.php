<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Event;

/**
 * A care episode was closed (26-care-coordination-domain.md §11; ADR-153 D-G).
 * Closing does NOT cascade to member encounters or links (Invariant 7.8).
 *
 * PHI-safe: identifiers only (ADR-136 deny-list — the close reason is
 * audit-only).
 */
final readonly class CareEpisodeClosed
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $careEpisodeId,
        public string $patientId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
