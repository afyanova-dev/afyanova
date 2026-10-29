<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Contracts\Event;

/**
 * A prescription was administratively cancelled — RECEIVED | ACTIVE → CANCELLED
 * (16-pharmacy-domain.md §55, §111; ADR-181 D-F). This is Pharmacy's
 * administrative cancellation of the RECORD — distinct from a clinical
 * discontinuation (Clinical owns that; §53–§55).
 *
 * Written to `shared_outbox_events` in the `CancelPrescription` transaction and
 * relayed asynchronously; idempotent consumers key on `eventId`. NOT broadcast.
 * NO Pharm.1 consumer.
 *
 * PHI-MINIMIZED: `reason` here is the CODED transition value 'CANCELLED', NOT
 * the free-text `cancel_reason` column (the ADR-136 deny-list).
 */
final readonly class PrescriptionCancelled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $prescriptionId,
        public ?string $prescribingDecisionId,
        public string $cancelledByUserId,
        public string $reason,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
