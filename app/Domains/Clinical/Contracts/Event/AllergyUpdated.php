<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * An allergy assertion's clinical content was revised or corrected in place
 * (12-clinical-domain.md §79, §165; ADR-136, ADR-141 D-Q). The allergy keeps
 * its stable identity; the pre-change state is preserved in
 * `clin_allergy_history`. Written to `shared_outbox_events` in the
 * `UpdateAllergy` transaction and relayed asynchronously; idempotent consumers
 * key on `eventId`. NOT broadcast.
 *
 * STRICTLY PHI-MINIMISED (IC §7.1.1; ADR-136; ADR-141 D-Q): identifiers plus
 * the coded `changeType` (`REVISION` | `CORRECTION`), the RESULTING coded
 * `category` / `clinicalStatus` / `verificationStatus` and `substanceCodeSystem`
 * (the SYSTEM only), and the envelope `occurredAt`. NO substance name, NO
 * substance code, NO reaction text, NO severity, NO onset date, NO free-text
 * correction / revision `reason`, NO previous-state values, NO demographics.
 */
final readonly class AllergyUpdated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $allergyId,
        public string $changeType,
        public string $category,
        public string $clinicalStatus,
        public string $verificationStatus,
        public ?string $substanceCodeSystem,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
