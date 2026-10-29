<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Event;

/**
 * A purchase order was cancelled (19-procurement-domain.md §73, §74; ADR-171
 * D-K). Supplier Integration and Inventory (a PO with expected deliveries no
 * longer stands) consume it.
 *
 * Coded values + identifiers + timestamps only — NO cancellation reason (the
 * ADR-136 deny-list; §229).
 */
final readonly class PurchaseOrderCancelled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $purchaseOrderId,
        public string $poNumber,
        public string $supplierId,
        public string $cancelledByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
