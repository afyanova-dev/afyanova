<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Event;

/**
 * A care episode was transferred out — the longitudinal responsibility moves
 * to another facility / provider (26-care-coordination-domain.md §8, §11;
 * ADR-153 D-G).
 *
 * PHI-safe: identifiers only (ADR-136 deny-list — the reason is audit-only).
 */
final readonly class CareEpisodeTransferredOut
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
