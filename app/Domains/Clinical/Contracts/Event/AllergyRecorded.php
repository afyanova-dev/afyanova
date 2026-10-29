<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * An immune-mediated-hypersensitivity assertion was added to a patient's
 * allergy safety record (12-clinical-domain.md §79, §165; ADR-136, ADR-141
 * D-Q). Written to `shared_outbox_events` in the `RecordAllergy` transaction
 * and relayed asynchronously; idempotent consumers key on `eventId`. NOT
 * broadcast.
 *
 * STRICTLY PHI-MINIMISED (IC §7.1.1; ADR-136; ADR-141 D-Q — the substance name,
 * substance code, reaction text, severity and any clinical `reason` are
 * clinical detail, deny-listed): identifiers plus coded values only — the
 * `category`, the two coded status axes, and the `substanceCodeSystem` (the
 * SYSTEM, never the code — the `diagnosisCodeSystem` precedent; null / absent
 * when the allergy carries no code) — plus the envelope `occurredAt`. A
 * consumer that needs the clinical detail queries the Clinical reader under its
 * own authorization.
 */
final readonly class AllergyRecorded
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $allergyId,
        public string $category,
        public string $clinicalStatus,
        public string $verificationStatus,
        public ?string $substanceCodeSystem,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
