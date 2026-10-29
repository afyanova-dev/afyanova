<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Event;

/**
 * A care episode was opened (26-care-coordination-domain.md §11; ADR-153 D-G).
 *
 * PHI-safe: identifiers + coded values only (ADR-136 deny-list).
 */
final readonly class CareEpisodeOpened
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $careEpisodeId,
        public string $patientId,
        public string $type,
        public ?string $subtype,
        public bool $override,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
