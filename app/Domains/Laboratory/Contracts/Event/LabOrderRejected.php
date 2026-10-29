<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Event;

/**
 * The laboratory rejected an order (ORDERED → REJECTED)
 * (14-laboratory-domain.md §24, §138; ADR-157 D-H). The free-text
 * `rejection_reason` is row-only and is NOT carried here (ADR-136 deny-list).
 */
final readonly class LabOrderRejected
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
