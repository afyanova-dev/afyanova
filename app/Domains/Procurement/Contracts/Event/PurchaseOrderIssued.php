<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Event;

/**
 * A purchase order was issued to the supplier (19-procurement-domain.md §44,
 * §124, §238; ADR-171 D-K). THE key Procurement event — the external accounting
 * / ERP, Supplier Integration, Notifications and Inventory (to expect delivery)
 * all consume it in their phases.
 *
 * Coded values + identifiers + timestamps only (ADR-136 / §229).
 */
final readonly class PurchaseOrderIssued
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $purchaseOrderId,
        public string $poNumber,
        public string $supplierId,
        public string $currency,
        public bool $emergency,
        public string $issuedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
