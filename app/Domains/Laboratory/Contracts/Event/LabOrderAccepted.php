<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Event;

/**
 * The laboratory accepted an order for processing (ORDERED → ACCEPTED)
 * (14-laboratory-domain.md §24, §138; ADR-157 D-H).
 *
 * PHI-safe: identifiers and coded values only (ADR-136 deny-list).
 */
final readonly class LabOrderAccepted
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
