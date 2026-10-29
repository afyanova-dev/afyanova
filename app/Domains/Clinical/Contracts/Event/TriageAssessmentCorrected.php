<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A recorded triage assessment was corrected / re-triaged by a superseding
 * assessment (ADR-136, ADR-145 D-F / D-J). The original assessment's clinical
 * content is untouched; a new assessment carrying the current values supersedes
 * it. Written to `shared_outbox_events` in the `CorrectTriageAssessment`
 * transaction and relayed asynchronously; idempotent consumers key on
 * `eventId`. NOT broadcast.
 *
 * Carries the RESULTING `triagePriority` — a re-triage may change it, and the
 * PHASE-12 Queueing consumer needs the CURRENT priority (ADR-145 D-J / R-1: two
 * events, the C.2 / C.3 `…Corrected` precedent).
 *
 * STRICTLY PHI-FREE (IC §7.1.1; ADR-136; ADR-145 D-J): identifiers plus the
 * coded `triagePriority`, the supersession link and the envelope `occurredAt`
 * ONLY. NO chief complaint, NO acuity, NO consciousness, NO correction `reason`
 * (that free text is clinical data on the new assessment row), NO demographics.
 */
final readonly class TriageAssessmentCorrected
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $triageAssessmentId,
        public string $supersedesTriageAssessmentId,
        public string $triagePriority,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
