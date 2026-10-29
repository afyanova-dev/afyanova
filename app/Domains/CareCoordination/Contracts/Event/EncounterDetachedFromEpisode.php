<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Event;

/**
 * An encounter was detached from a care episode
 * (26-care-coordination-domain.md §11; ADR-153 D-G). The link row is kept
 * (status DETACHED), never hard-deleted (§256).
 *
 * PHI-safe: identifiers only (ADR-136 deny-list — the detach reason is
 * audit-only).
 */
final readonly class EncounterDetachedFromEpisode
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $careEpisodeEncounterId,
        public string $careEpisodeId,
        public string $encounterId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
