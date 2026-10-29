<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Event;

/**
 * A purchase requisition was approved — sourcing / ordering may begin
 * (19-procurement-domain.md §21, §123, §237; ADR-171 D-K).
 *
 * Coded values + identifiers + timestamps only (the ADR-136 deny-list, extended
 * to commercial data by §229 / Inv 26 — NO `reason`, NO estimated value, NO
 * supplier detail).
 */
final readonly class PurchaseRequisitionApproved
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $requisitionId,
        public string $requisitionNumber,
        public string $priority,
        public bool $emergency,
        public string $approvedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
