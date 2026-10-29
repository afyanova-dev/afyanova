<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A vitals set was recorded against an encounter (12-clinical-domain.md §79,
 * §165; ADR-136, ADR-138). Written to `shared_outbox_events` in the
 * `RecordVitalSigns` transaction and relayed asynchronously; idempotent
 * consumers key on `eventId`. NOT broadcast.
 *
 * STRICTLY PHI-FREE (IC §7.1.1; ADR-136 deny-list explicitly names "vital-sign
 * values"; ADR-138 D13): identifiers plus the envelope `occurredAt` ONLY. NO
 * measurement values, NO units, NO `measuredTypes` list, NO abnormal flags, NO
 * free text, NO patient demographics. A consumer that needs the measurements
 * queries `ClinicalApi` under its own authorization.
 */
final readonly class VitalSignsRecorded
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $vitalSignsObservationId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
