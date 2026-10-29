<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Event;

/**
 * A laboratory order was cancelled by the ordering side (DRAFT|ORDERED →
 * CANCELLED) (14-laboratory-domain.md §24, §138; ADR-157 D-H). The free-text
 * `cancellation_reason` is row-only and is NOT carried here (ADR-136 deny-list).
 */
final readonly class LabOrderCancelled
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
