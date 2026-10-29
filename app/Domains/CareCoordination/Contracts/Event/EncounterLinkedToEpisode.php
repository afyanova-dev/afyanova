<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Event;

/**
 * An encounter was linked to a care episode (26-care-coordination-domain.md
 * §11; ADR-153 D-G). Emitted by the CareEpisodeEncounter aggregate.
 *
 * PHI-safe: identifiers only (ADR-136 deny-list — `role` / `added_reason`
 * never carried).
 */
final readonly class EncounterLinkedToEpisode
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $careEpisodeEncounterId,
        public string $careEpisodeId,
        public string $encounterId,
        public string $patientId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
