<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Event;

/**
 * A recollection was requested for a rejected specimen (14-laboratory-domain.md
 * §36, §138; ADR-158 D-H). PHI-safe: identifiers only.
 */
final readonly class SpecimenRecollectionRequested
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $specimenId,
        public string $orderId,
        public string $accessionNumber,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
