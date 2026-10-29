<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A clinical note was originated as a `DRAFT` (12-clinical-domain.md §79, §165;
 * ADR-136). Fired at `DRAFT` creation — "a note was created" is a distinct fact
 * from `ClinicalNoteSigned`, and downstream (a PHASE-16 pending-documentation
 * indicator, PHASE-14 reporting) tracks origination. Written to
 * `shared_outbox_events` in the `CreateClinicalNote` transaction and relayed
 * asynchronously; idempotent consumers key on `eventId`.
 *
 * STRICTLY PHI-FREE (IC §7.1.1; ADR-136 allow-list): identifiers plus the
 * coded `noteType` and `status` ONLY. NO note title or body, NO patient
 * demographics, NO free text.
 */
final readonly class ClinicalNoteCreated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $clinicalNoteId,
        public string $noteType,
        public string $status,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
