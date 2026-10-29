<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A correction was appended to a SIGNED clinical note — `SIGNED → SIGNED` with
 * one new `clin_note_amendments` row (12-clinical-domain.md §14, §165;
 * ADR-136). The signed body is untouched; the amendment is an append. Written
 * to `shared_outbox_events` in the `AmendClinicalNote` transaction and relayed
 * asynchronously; idempotent consumers key on `eventId`.
 *
 * STRICTLY PHI-FREE (IC §7.1.1; ADR-136 allow-list): identifiers plus the
 * coded `amendmentType` ONLY. The amendment body and its `reason` string are
 * clinical detail and are NOT carried — a consumer queries `ClinicalApi` under
 * its own authorization.
 */
final readonly class ClinicalNoteAmended
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $clinicalNoteId,
        public string $amendmentType,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
