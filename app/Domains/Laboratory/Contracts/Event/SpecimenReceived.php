<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Event;

/**
 * A specimen was received at the laboratory (14-laboratory-domain.md §138;
 * ADR-158 D-H). PHI-safe: identifiers and coded values only.
 */
final readonly class SpecimenReceived
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
