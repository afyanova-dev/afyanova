<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Contracts\Dto;

/**
 * One Medication Pickup (16-pharmacy-domain.md §29–§31, §129; ADR-186 D-F).
 * Returned by `GetPickup` / `SearchPickups` via `PickupReader`.
 *
 * NOT an event payload. NOT `#[TypeScript]` (no PHASE 9 frontend consumer,
 * ADR-124). `recipientName` / `verificationNote` / `outcomeNote` ARE returned to
 * an authorised reader (`pharmacy.access`) — ROW-ONLY means "not in events /
 * audit metadata" (the `PharmacyOrderSummary.reasonNote` precedent).
 */
final readonly class PickupSummary
{
    public function __construct(
        public string $pickupId,
        public string $pickupNumber,
        public string $facilityId,
        public string $dispensingId,
        public string $pharmacyOrderId,
        public string $prescriptionId,
        public string $patientId,
        public string $status,
        public ?string $recipientType,
        public ?string $recipientName,
        public ?string $verificationNote,
        public ?string $collectedByUserId,
        public ?string $collectedAt,
        public ?string $outcomeReasonCode,
        public ?string $outcomeNote,
        public string $occurredAt,
        public string $recordedAt,
    ) {}
}
