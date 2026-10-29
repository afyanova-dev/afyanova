<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Event;

/**
 * An issued purchase order was amended (19-procurement-domain.md §67, §68,
 * §239; ADR-171 D-K). Inventory, Finance and Supplier Integration must know the
 * PO changed.
 *
 * Coded values + identifiers + timestamps only — NO previous / new values, NO
 * reason (the ADR-136 deny-list; §229).
 */
final readonly class PurchaseOrderAmended
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $purchaseOrderId,
        public string $poNumber,
        public string $amendmentType,
        public int $amendmentSequence,
        public string $amendedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
