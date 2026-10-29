<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Contracts\Event;

/**
 * Medication was collected from the pharmacy — a `Pickup` reached `PICKED_UP`
 * (16-pharmacy-domain.md §29, §85, §86; ADR-186 D-F).
 *
 * Written to `shared_outbox_events` in the `RecordPickup` transaction and relayed
 * asynchronously; idempotent consumers key on `eventId`. NOT broadcast. NO
 * Pharm.3c consumer — named future consumers: patient notifications, medication
 * adherence (§85).
 *
 * PHI-MINIMIZED (the ADR-136 deny-list, IC §7.1.1): identifiers + the coded
 * `recipientType` + `collectedByUserId`. NO recipient name, NO patient
 * demographics, NO verification detail.
 */
final readonly class MedicationPickedUp
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $pickupId,
        public string $dispensingId,
        public string $pharmacyOrderId,
        public string $prescriptionId,
        public string $recipientType,
        public string $collectedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
