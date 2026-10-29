<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Dto;

/**
 * Minimal summary read of one imaging order and its requested services
 * (ADR-165 D-H). A protected read model, not an event payload — the ADR-136
 * deny-list applies to events / audit, not to a gated query result for an
 * authorized caller, so `clinicalIndicationText` / `rejectionReason` /
 * `cancellationReason` are present here.
 */
final readonly class ImagingOrderSummary
{
    /**
     * @param  list<ImagingOrderItemSummary>  $items
     */
    public function __construct(
        public string $orderId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $orderNumber,
        public string $priority,
        public ?string $clinicalIndicationRef,
        public ?string $clinicalIndicationText,
        public ?string $rejectionReason,
        public ?string $cancellationReason,
        public string $status,
        public array $items,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
