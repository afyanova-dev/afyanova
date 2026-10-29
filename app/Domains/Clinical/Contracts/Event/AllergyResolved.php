<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * An allergy was resolved (`clinical_status ACTIVE → RESOLVED`) — the allergy
 * was clinically real but is no longer active (12-clinical-domain.md §31, §79,
 * §165; ADR-136, ADR-141 D-Q). The allergy keeps its stable identity;
 * `resolved_at` is set once. Written to `shared_outbox_events` in the
 * `ResolveAllergy` transaction and relayed asynchronously; idempotent consumers
 * key on `eventId`. NOT broadcast.
 *
 * STRICTLY PHI-MINIMISED (IC §7.1.1; ADR-136; ADR-141 D-Q): identifiers plus
 * the envelope `occurredAt` and the coded lifecycle timestamp `resolvedAt`. NO
 * substance name / code, NO reaction text, NO severity, NO onset date, NO free
 * text, NO demographics.
 */
final readonly class AllergyResolved
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $allergyId,
        public string $resolvedAt,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
