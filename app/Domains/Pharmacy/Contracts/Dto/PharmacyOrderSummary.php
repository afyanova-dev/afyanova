<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Contracts\Dto;

/**
 * One Pharmacy Order (16-pharmacy-domain.md §22, §185, §186; ADR-182 D-E).
 * Returned by `GetPharmacyOrder` / `SearchPharmacyOrders` via `PharmacyOrderReader`.
 *
 * NOT an event payload — `reasonNote` IS returned to an authorised reader
 * (`pharmacy.access`). NOT `#[TypeScript]` (no PHASE 9 frontend consumer, ADR-124).
 */
final readonly class PharmacyOrderSummary
{
    public function __construct(
        public string $pharmacyOrderId,
        public string $orderNumber,
        public string $facilityId,
        public string $prescriptionId,
        public string $patientId,
        public ?string $dispensingLocationId,
        public string $priority,
        public string $status,
        public ?string $validatedByUserId,
        public ?string $rejectReasonCode,
        public ?string $holdReasonCode,
        public ?string $cancelReasonCode,
        public ?string $reasonNote,
        public ?string $cancelledByUserId,
        public string $occurredAt,
        public string $recordedAt,
    ) {}
}
