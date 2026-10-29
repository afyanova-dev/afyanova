<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Event;

/**
 * The ordering side withdrew an imaging order before Radiology accepted it
 * (DRAFT|ORDERED → CANCELLED) (15-radiology-domain.md §20, §55, §177;
 * ADR-165 D-I).
 *
 * PHI-safe: identifiers and coded values only (ADR-136 deny-list — the
 * free-text cancellation reason is row-only + on the audit row, never here).
 */
final readonly class ImagingOrderCancelled
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
