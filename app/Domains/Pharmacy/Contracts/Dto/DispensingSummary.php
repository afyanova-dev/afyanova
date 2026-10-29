<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Contracts\Dto;

/**
 * One Dispensing record (16-pharmacy-domain.md §24, §127, §255; ADR-184 D-I).
 * Returned by `GetDispensing` / `GetPharmacyOrderDispensings` via
 * `DispensingReader`.
 *
 * NOT an event payload. NOT `#[TypeScript]` (no PHASE 9 frontend consumer,
 * ADR-124). `quantity` / `authorizedQuantityValue` are decimal:4 numeric
 * strings (the `AvailableStockSummary` / `PrescribingDecisionSummary` precedent).
 *
 * ADR-185 D-F — the `reversal*` block is APPENDED, non-null only when this
 * dispensing has been reversed (§128 — the original row is preserved).
 */
final readonly class DispensingSummary
{
    public function __construct(
        public string $dispensingId,
        public string $dispensingNumber,
        public string $facilityId,
        public string $pharmacyOrderId,
        public string $prescriptionId,
        public string $patientId,
        public string $itemId,
        public string $locationId,
        public ?string $batchNumber,
        public string $quantity,
        public ?string $quantityUnit,
        public ?string $authorizedQuantityValue,
        public ?string $authorizedQuantityUnit,
        public bool $dispensedWithoutCeiling,
        public string $reservationId,
        public string $movementId,
        public ?string $financialClearanceRef,
        public string $dispensedByUserId,
        public string $status,
        public string $occurredAt,
        public string $recordedAt,
        public ?string $reversalId = null,
        public ?string $reversalNumber = null,
        public ?string $reversalReasonCode = null,
        public ?string $reversedByUserId = null,
        public ?string $reversedAt = null,
    ) {}
}
