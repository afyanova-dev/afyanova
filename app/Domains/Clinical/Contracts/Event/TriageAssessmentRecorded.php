<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A triage assessment was recorded against an encounter (ADR-105, ADR-136,
 * ADR-145 D-J). Written to `shared_outbox_events` in the
 * `RecordTriageAssessment` transaction and relayed asynchronously; idempotent
 * consumers key on `eventId`. NOT broadcast.
 *
 * CONSUMER-LESS in PHASE 6 — Queueing & Patient Flow (PHASE 12) will subscribe
 * in its own increment to derive operational queue order from `triagePriority`.
 * Clinical DETERMINES the priority; Queueing LATER CONSUMES it; Queueing never
 * computes clinical urgency (ADR-105). The ADR-137 / ADR-143 event-only-to-an-
 * unbuilt-domain precedent.
 *
 * STRICTLY PHI-FREE (IC §7.1.1; ADR-136 allow-list; ADR-145 D-J): identifiers
 * plus the coded `triagePriority` and the envelope `occurredAt` ONLY. NO chief
 * complaint, NO acuity, NO consciousness, NO correction `reason`, NO
 * `sourceVitalsObservationId`, NO patient demographics.
 */
final readonly class TriageAssessmentRecorded
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $triageAssessmentId,
        public string $triagePriority,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
