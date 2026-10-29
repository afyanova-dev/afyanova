<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A prescribing decision was recorded or corrected (12-clinical-domain.md §83, §165;
 * ADR-136; ADR-143 D-R). Written to `shared_outbox_events` in the
 * `RecordPrescribingDecision` / `CorrectPrescribingDecision` transaction and
 * relayed asynchronously; idempotent consumers key on `eventId`. NOT broadcast.
 *
 * PHI-MINIMIZED (ADR-136, ADR-143 D-R): identifiers plus the coded
 * `medicationCodeSystem` (nullable), coded `route` (nullable), coded `urgency`
 * (nullable), and `changeType` (null on initial record, 'CORRECTION' on correction).
 *
 * FORBIDDEN (the ADR-136 deny-list): medicationName, medicationCode, dose,
 * frequency, duration, quantity, quantityValue / quantityUnit (ADR-183 D-I —
 * the structured prescribed quantity never rides the wire), dosingInstruction,
 * therapeuticIntent, indication, correction rationale, patient demographics.
 */
final readonly class PrescriptionIssued
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $prescribingDecisionId,
        public string $authorUserId,
        public ?string $medicationCodeSystem,
        public ?string $route,
        public ?string $urgency,
        public ?string $changeType,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
