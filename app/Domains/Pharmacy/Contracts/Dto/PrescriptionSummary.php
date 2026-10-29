<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Contracts\Dto;

/**
 * One Prescription record (16-pharmacy-domain.md §19, §185; ADR-181 D-F).
 * Returned by `GetPrescription` / `GetPatientPrescriptions` / `SearchPrescriptions`
 * via `PrescriptionReader`.
 *
 * NOT an event payload — the clinical snapshot IS returned to an authorized
 * reader (`pharmacy.access`). NOT `#[TypeScript]` (no PHASE 9 frontend consumer,
 * ADR-124). The snapshot fields are null while the record is still `RECEIVED`
 * (before `AcceptPrescription`).
 */
final readonly class PrescriptionSummary
{
    public function __construct(
        public string $prescriptionId,
        public string $prescriptionNumber,
        public string $facilityId,
        public string $patientId,
        public ?string $encounterId,
        public ?string $prescribingDecisionId,
        public string $authorUserId,
        public ?string $changeType,
        public ?string $medicationCodeSystem,
        public ?string $route,
        public ?string $urgency,
        public ?string $medicationName,
        public ?string $medicationCode,
        public ?string $dose,
        public ?string $frequency,
        public ?string $duration,
        public ?string $prescribedQuantity,
        public ?string $dosingInstruction,
        public string $status,
        public ?string $acceptedByUserId,
        public ?string $cancelledByUserId,
        public ?string $cancelReason,
        public string $occurredAt,
        public string $recordedAt,
        public ?string $source = null,
        public ?string $prescriberName = null,
        public ?string $transcriptionNote = null,
        public ?string $inventoryItemId = null,
        public ?string $externalIntakeId = null,
    ) {}
}
