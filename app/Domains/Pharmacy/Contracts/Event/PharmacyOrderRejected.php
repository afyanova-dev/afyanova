<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Contracts\Event;

/**
 * A pharmacist rejected a Pharmacy Order — RECEIVED | VALIDATED → REJECTED
 * (16-pharmacy-domain.md §124; ADR-182 D-E).
 *
 * Written to `shared_outbox_events` in the `RejectPharmacyOrder` transaction and
 * relayed asynchronously; idempotent consumers key on `eventId`. NOT broadcast.
 * NO Pharm.2a consumer (Billing / Notifications later).
 *
 * PHI-MINIMIZED: identifiers plus the CODED `reasonCode` (§124) — never the
 * free-text `reason_note` column (the ADR-136 deny-list).
 */
final readonly class PharmacyOrderRejected
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $pharmacyOrderId,
        public string $prescriptionId,
        public string $rejectedByUserId,
        public string $reasonCode,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
