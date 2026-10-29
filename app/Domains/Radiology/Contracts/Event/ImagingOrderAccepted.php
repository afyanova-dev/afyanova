<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Event;

/**
 * Radiology accepted an imaging order for processing (ORDERED → ACCEPTED)
 * (15-radiology-domain.md §20, §177; ADR-165 D-I).
 *
 * PHI-safe: identifiers and coded values only (ADR-136 deny-list).
 */
final readonly class ImagingOrderAccepted
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $orderId,
        public string $orderNumber,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
