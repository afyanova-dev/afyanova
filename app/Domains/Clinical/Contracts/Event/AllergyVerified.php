<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * An allergy assertion's verification status changed (12-clinical-domain.md
 * §31, §32, §79, §165; ADR-136, ADR-141 D-Q). One event covers both
 * `VerifyAllergy` outcomes:
 *
 *   - `verificationStatus = CONFIRMED` — `clinicalStatus` is unchanged;
 *   - `verificationStatus = REFUTED`   — `clinicalStatus` is `INACTIVE`
 *                                        (the ADR-141 D-D biconditional, applied
 *                                        atomically in the aggregate).
 *
 * Written to `shared_outbox_events` in the `VerifyAllergy` transaction and
 * relayed asynchronously; idempotent consumers key on `eventId`. NOT broadcast.
 *
 * STRICTLY PHI-MINIMISED (IC §7.1.1; ADR-136; ADR-141 D-Q): identifiers plus
 * the coded `verificationStatus`, the RESULTING coded `clinicalStatus`, and the
 * envelope `occurredAt`. NO substance name / code, NO reaction text, NO
 * severity, NO refutation `reason` (that is clinical data on the
 * `clin_allergy_history` row only), NO previous-state values, NO demographics.
 */
final readonly class AllergyVerified
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $allergyId,
        public string $verificationStatus,
        public string $clinicalStatus,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
