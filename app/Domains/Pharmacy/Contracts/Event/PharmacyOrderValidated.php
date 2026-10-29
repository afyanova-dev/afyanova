<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Contracts\Event;

/**
 * A pharmacist validated a Pharmacy Order — RECEIVED → VALIDATED; the
 * prescription is cleared for fulfilment (16-pharmacy-domain.md §106, §261;
 * ADR-182 D-E).
 *
 * Written to `shared_outbox_events` in the `ValidatePharmacyOrder` transaction
 * and relayed asynchronously; idempotent consumers key on `eventId`. NOT
 * broadcast. NO Pharm.2a consumer — Pharm.3 (dispensing) subscribes to pick up
 * VALIDATED orders.
 *
 * PHI-MINIMIZED (the ADR-136 deny-list, IC §7.1.1): identifiers only.
 */
final readonly class PharmacyOrderValidated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $pharmacyOrderId,
        public string $prescriptionId,
        public string $validatedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
