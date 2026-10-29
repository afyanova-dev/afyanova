<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A recorded diagnosis was corrected by a superseding diagnosis
 * (12-clinical-domain.md §94; ADR-136, ADR-139 D4 / D10). The original
 * diagnosis's clinical content is untouched; a new diagnosis carrying the
 * corrected coding / description / status supersedes it. Written to
 * `shared_outbox_events` in the `CorrectDiagnosis` transaction and relayed
 * asynchronously; idempotent consumers key on `eventId`. NOT broadcast.
 *
 * STRICTLY PHI-FREE (IC §7.1.1; ADR-136; ADR-139 D10): identifiers plus the
 * envelope `occurredAt` and the supersession link ONLY. NO diagnosis code, NO
 * description, NO correction `reason` (that free text is clinical data on the
 * new diagnosis row), NO demographics.
 */
final readonly class DiagnosisCorrected
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $diagnosisId,
        public string $supersedesDiagnosisId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
