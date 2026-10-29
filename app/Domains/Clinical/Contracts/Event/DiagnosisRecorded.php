<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A diagnosis was recorded against an encounter (12-clinical-domain.md §79,
 * §165; ADR-136, ADR-139 D10). Written to `shared_outbox_events` in the
 * `RecordDiagnosis` transaction and relayed asynchronously; idempotent
 * consumers key on `eventId`. NOT broadcast.
 *
 * STRICTLY PHI-FREE (IC §7.1.1; ADR-136 — the diagnosis CODE and the free-text
 * DESCRIPTION are clinical detail, deny-listed; only `diagnosisCodeSystem` —
 * the SYSTEM, not the code — is allow-listed): identifiers plus the coded
 * `diagnosisCodeSystem` (nullable / absent when the diagnosis carries no code)
 * and `clinicalStatus`, plus the envelope `occurredAt`. NO diagnosis code, NO
 * description, NO rank, NO free text, NO patient demographics.
 */
final readonly class DiagnosisRecorded
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $diagnosisId,
        public ?string $diagnosisCodeSystem,
        public string $clinicalStatus,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
