<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A recorded vitals observation was corrected by a superseding observation
 * (12-clinical-domain.md §94; ADR-136, ADR-138). The original observation's
 * measurement values are untouched; a new observation carrying the corrected
 * values supersedes it. Written to `shared_outbox_events` in the
 * `CorrectVitalSignsObservation` transaction and relayed asynchronously;
 * idempotent consumers key on `eventId`. NOT broadcast.
 *
 * STRICTLY PHI-FREE (IC §7.1.1; ADR-136; ADR-138 D13): identifiers plus the
 * envelope `occurredAt` and the supersession link ONLY. NO measurement values,
 * NO units, NO correction `reason` (that free text is clinical data on the new
 * observation row), NO demographics.
 */
final readonly class VitalSignsObservationCorrected
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $vitalSignsObservationId,
        public string $supersedesObservationId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
