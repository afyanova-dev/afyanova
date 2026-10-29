<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Contracts\Event;

/**
 * A pharmacist accepted a prescription into the pharmacy — the record moved
 * RECEIVED → ACTIVE and the immutable clinical snapshot was written
 * (16-pharmacy-domain.md §19, §24, §261; ADR-181 D-F).
 *
 * Written to `shared_outbox_events` in the `AcceptPrescription` transaction and
 * relayed asynchronously; idempotent consumers key on `eventId`. NOT broadcast.
 * NO Pharm.1 consumer (dispensing / Billing / Notifications subscribe later).
 *
 * PHI-MINIMIZED (the ADR-136 deny-list, IC §7.1.1): identifiers plus the coded
 * `medicationCodeSystem` (the SYSTEM, not the code), coded `route`, coded
 * `urgency`. FORBIDDEN: medicationName, medicationCode, dose, frequency,
 * duration, quantity, dosingInstruction, patient demographics.
 */
final readonly class PrescriptionAccepted
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $prescriptionId,
        public string $prescribingDecisionId,
        public string $acceptedByUserId,
        public ?string $medicationCodeSystem,
        public ?string $route,
        public ?string $urgency,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
