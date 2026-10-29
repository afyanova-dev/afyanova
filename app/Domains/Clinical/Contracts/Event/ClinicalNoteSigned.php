<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A clinical note was attested — `DRAFT → SIGNED` (12-clinical-domain.md §80,
 * §165; ADR-136). The canonical consumed clinical-note event: Reporting,
 * Notifications and Documents (later phases) react to a note becoming a
 * high-integrity record. Written to `shared_outbox_events` in the
 * `SignClinicalNote` transaction and relayed asynchronously; idempotent
 * consumers key on `eventId` (a duplicate must not produce two documents /
 * notifications).
 *
 * STRICTLY PHI-FREE (IC §7.1.1; ADR-136 allow-list): identifiers only. The
 * outcome is structurally fixed as `SIGNED`, so no status discriminator is
 * carried. NO title / body / signer name / free text.
 */
final readonly class ClinicalNoteSigned
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $clinicalNoteId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
