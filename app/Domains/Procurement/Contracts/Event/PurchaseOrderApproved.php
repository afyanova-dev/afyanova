<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Event;

/**
 * A purchase order was approved (19-procurement-domain.md §44, §45; ADR-171
 * D-K). A plain fact — NO "commitment" / budget-accounting framing (that is
 * ADR-110 territory, out of V4). Consumers deferred (the ADR-137 discipline).
 *
 * Coded values + identifiers + timestamps only (ADR-136 / §229).
 */
final readonly class PurchaseOrderApproved
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
        public string $approvedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
