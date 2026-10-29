<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Contracts\Event;

/**
 * A dispensing was reversed — a `DispensingReversal` record was created and the
 * stock returned (16-pharmacy-domain.md §60, §102–§104, §128; ADR-185 D-F). The
 * inverse fact of {@see MedicationDispensed}.
 *
 * Written to `shared_outbox_events` in the `ReverseDispensing` transaction and
 * relayed asynchronously; idempotent consumers key on `eventId`. NOT broadcast.
 * NO Pharm.3b consumer — the named future consumer is Billing refund
 * reconciliation (§102–§104, PHASE 10).
 *
 * PHI-MINIMIZED (the ADR-136 deny-list, IC §7.1.1): identifiers + the coded
 * `itemId` + `quantity` + `reasonCode`. NO medication name / code, NO patient
 * demographics.
 */
final readonly class MedicationDispensingReversed
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $dispensingReversalId,
        public string $dispensingId,
        public string $pharmacyOrderId,
        public string $prescriptionId,
        public string $itemId,
        public string $quantity,
        public string $reasonCode,
        public string $reversedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
